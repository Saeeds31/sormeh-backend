<?php

namespace Modules\Payment\Drivers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Payment\Contracts\GatewayInterface;
use Modules\Payment\Exceptions\PaymentFailedException;
use Modules\Payment\Services\MoneyService;

class SnappPayDriver implements GatewayInterface
{
    protected string $baseUrl;
    protected string $clientId;
    protected string $clientSecret;
    protected string $username;
    protected string $password;

    public function __construct(
        protected MoneyService $money
    ) {
        $this->baseUrl = rtrim(config('payment.drivers.snapppay.base_url'), '/');
        $this->clientId = config('payment.drivers.snapppay.client_id');
        $this->clientSecret = config('payment.drivers.snapppay.client_secret');
        $this->username = config('payment.drivers.snapppay.username');
        $this->password = config('payment.drivers.snapppay.password');
    }

    /**
     * دریافت توکن Bearer با کش (OAuth Password Grant)
     */
    protected function getToken(): string
    {
        return Cache::remember('snapppay_token', 3500, function () {
            $response = Http::asForm()->post("{$this->baseUrl}/api/online/v1/oauth/token", [
                'grant_type' => 'password',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'username' => $this->username,
                'password' => $this->password,
            ])->throw()->json();

            return $response['access_token'];
        });
    }

    public function pay(GatewayTransaction $transaction): string
    {
        $token = $this->getToken();

        // مبلغ باید به ریال باشد
        $amountInRial = $this->money->tomanToRial($transaction->amount);

        $response = Http::withToken($token)
            ->post("{$this->baseUrl}/api/online/payment/v1/token", [
                'amount' => $amountInRial,
                'transactionId' => (string) $transaction->id,
                'returnURL' => route('payment.callback', 'snapp_pay'),
                'mobile' => $transaction->payable->user->mobile ?? '',
            ])->throw()->json();

        // ذخیره paymentToken در فیلد authority
        $transaction->update([
            'authority' => $response['paymentToken'],
            'request_data' => $response,
        ]);

        return $response['paymentPageUrl'];
    }

    /**
     * Verify + Settle
     * اسنپ‌پی نیاز به انجام هر دو مرحله دارد.
     */
    public function verify(GatewayTransaction $transaction, array $callback): array
    {
        $token = $this->getToken();
        $paymentToken = $transaction->authority;

        // ==================== مرحله ۱: Verify ====================
        try {
            $verifyResponse = Http::withToken($token)
                ->timeout(30)
                ->post("{$this->baseUrl}/api/online/payment/v1/verify", [
                    'paymentToken' => $paymentToken,
                ])->throw()->json();
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // اگر verify تایم‌اوت شد یا خطای شبکه داد، وضعیت را استعلام می‌کنیم
            Log::channel('payment')->warning('SnappPay verify failed, checking status', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            $status = $this->getStatus($token, $paymentToken);

            if (($status['status'] ?? '') === 'VERIFY') {
                // قبلاً verify شده، ادامه می‌دهیم
                $verifyResponse = ['success' => true, 'transactionId' => $status['transactionId'] ?? null];
            } elseif (($status['status'] ?? '') === 'PENDING') {
                // هنوز در حال پردازش است، یک بار دیگر تلاش می‌کنیم
                $verifyResponse = Http::withToken($token)
                    ->timeout(30)
                    ->post("{$this->baseUrl}/api/online/payment/v1/verify", [
                        'paymentToken' => $paymentToken,
                    ])->throw()->json();
            } else {
                throw new PaymentFailedException(
                    'اسنپ‌پی: وضعیت پرداخت نامشخص است. لطفاً بعداً استعلام کنید.',
                    [],
                    -1
                );
            }
        }

        if (!($verifyResponse['success'] ?? false)) {
            throw new PaymentFailedException(
                'اسنپ‌پی: تایید پرداخت ناموفق بود.',
                $verifyResponse,
                $verifyResponse['errorCode'] ?? -1
            );
        }

        // ==================== مرحله ۲: Settle ====================
        try {
            $settleResponse = Http::withToken($token)
                ->timeout(30)
                ->post("{$this->baseUrl}/api/online/payment/v1/settle", [
                    'paymentToken' => $paymentToken,
                ])->throw()->json();
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // اگر settle تایم‌اوت شد، وضعیت را استعلام می‌کنیم
            Log::channel('payment')->warning('SnappPay settle failed, checking status', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            $status = $this->getStatus($token, $paymentToken);

            if (($status['status'] ?? '') === 'SETTLE') {
                // قبلاً settle شده، مشکلی نیست
                $settleResponse = ['success' => true, 'transactionId' => $status['transactionId'] ?? null];
            } else {
                // settle انجام نشده، تراکنش در حالت verify می‌ماند
                // این یک خطای جدی است و باید دستی پیگیری شود
                throw new PaymentFailedException(
                    'اسنپ‌پی: تسویه انجام نشد. لطفاً به صورت دستی پیگیری کنید.',
                    ['verify' => $verifyResponse, 'status' => $status],
                    -2
                );
            }
        }

        if (!($settleResponse['success'] ?? false)) {
            throw new PaymentFailedException(
                'اسنپ‌پی: تسویه ناموفق بود.',
                $settleResponse,
                $settleResponse['errorCode'] ?? -1
            );
        }

        // ذخیره اطلاعات در تراکنش
        $transaction->update([
            'verify_data' => [
                'verify' => $verifyResponse,
                'settle' => $settleResponse,
            ],
        ]);

        return [
            'success' => true,
            'ref_id' => $settleResponse['transactionId'] ?? $paymentToken,
            'response' => $settleResponse,
        ];
    }

    /**
     * استعلام وضعیت پرداخت (برای resilient workflows)
     */
    protected function getStatus(string $token, string $paymentToken): array
    {
        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get("{$this->baseUrl}/api/online/payment/v1/status", [
                    'paymentToken' => $paymentToken,
                ])->throw()->json();

            return $response;
        } catch (\Throwable $e) {
            Log::channel('payment')->error('SnappPay status query failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}