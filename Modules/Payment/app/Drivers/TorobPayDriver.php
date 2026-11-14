<?php

namespace Modules\Payment\Drivers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Orders\Models\Order;
use Modules\Payment\Contracts\GatewayInterface;
use Modules\Payment\Exceptions\PaymentFailedException;
use Modules\Payment\Services\MoneyService;

class TorobPayDriver implements GatewayInterface
{
    protected string $baseUrl;
    protected string $clientId;
    protected string $clientSecret;
    protected string $username;
    protected string $password;

    public function __construct(
        protected MoneyService $money
    ) {
        $this->baseUrl = rtrim(config('payment.drivers.torobpay.base_url'), '/');
        $this->clientId = config('payment.drivers.torobpay.client_id');
        $this->clientSecret = config('payment.drivers.torobpay.client_secret');
        $this->username = config('payment.drivers.torobpay.username');
        $this->password = config('payment.drivers.torobpay.password');
    }

    /**
     * دریافت توکن Bearer (OAuth Password Grant)
     */
    protected function getToken(): string
    {
        return Cache::remember('torobpay_token', 3500, function () {
            $basic = base64_encode($this->clientId . ':' . $this->clientSecret);

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $basic,
            ])->asForm()->post("{$this->baseUrl}/api/online/v1/oauth/token", [
                'grant_type' => 'password',
                'username' => $this->username,
                'password' => $this->password,
            ])->throw()->json();

            return $response['access_token'];
        });
    }

    public function pay(GatewayTransaction $transaction): string
    {
        $token = $this->getToken();
        $amountInRial = $this->money->tomanToRial($transaction->amount);

        $payable = $transaction->payable;
        $order = $payable instanceof Order ? $payable : null;
        $user = $order?->user;

        // ساخت cartList بر اساس آیتم‌های سفارش (طبق مستندات صفحه ۱۴ و ۱۵)
        $cartItems = [];
        if ($order) {
            foreach ($order->items as $item) {
                $cartItems[] = [
                    'id' => (string) $item->product_variant_id,
                    'name' => $item->product->title ?? 'محصول',
                    'count' => (int) $item->quantity,
                    'amount' => $this->money->tomanToRial((int) $item->price),
                    'category' => $item->product->categories->first()?->title ?? 'عمومی',
                    'commissionType' => 0,
                ];
            }
        }

        // ساختار کامل درخواست طبق مستندات صفحه ۱۳
        $payload = [
            'amount' => $amountInRial,
            'paymentMethodTypeDto' => 'ONLINE_CREDIT', // ← فیلد گمشده
            'returnURL' => route('payment.callback', 'torobpay'),
            'transactionId' => (string) $transaction->id,
            'mobile' => $user->mobile ?? '',
            'customer_full_name' => $user->full_name ?? 'کاربر',
            'registration_phone_number' => $user->mobile ?? '',
            'address' => $order?->address->address_line ?? '',
            'postalCode' => $order?->address->postal_code ?? '',
            'city' => $order?->address->city->name ?? '',
            'province' => $order?->address->province->name ?? '',
            'cartList' => [
                [
                    'cartId' => (string) $transaction->id,
                    'totalAmount' => $amountInRial,
                    'shippingAmount' => $this->money->tomanToRial((int) ($order->shipping_cost ?? 0)),
                    'isShipmentIncluded' => true,
                    'isTaxIncluded' => false,
                    'taxAmount' => 0,
                    'cartItems' => $cartItems,
                ],
            ],
        ];

        $response = Http::withToken($token)
            ->post("{$this->baseUrl}/api/online/payment/v1/token", $payload)
            ->throw()
            ->json();

        // بررسی پاسخ موفق طبق ساختار مستندات (صفحه ۳)
        if (!($response['successful'] ?? false)) {
            throw new \RuntimeException(
                $response['error']['message'] ?? 'خطا در ساخت تراکنش ترب‌پی.'
            );
        }

        $transaction->update([
            'authority' => $response['response']['paymentToken'],
            'request_data' => $response,
        ]);

        return $response['response']['paymentPageUrl'];
    }

    /**
     * Verify + Settle
     * ترب‌پی هم مثل اسنپ‌پی نیاز به انجام هر دو مرحله دارد.
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
            // ... (لاگ خطا)
            throw new PaymentFailedException(
                'ترب‌پی: تایید پرداخت با خطا مواجه شد. لطفاً وضعیت را استعلام کنید.',
                $e->response?->json() ?? [],
                $e->response?->status() ?? -1
            );
        }

        // لاگ کردن پاسخ برای دیباگ (بسیار مهم)
        Log::channel('payment')->info('TorobPay Verify Response', [
            'transaction_id' => $transaction->id,
            'response' => $verifyResponse,
        ]);

        // ✅ بررسی صحیح: از کلید 'successful' استفاده کن
        if (!($verifyResponse['successful'] ?? false)) {
            throw new PaymentFailedException(
                $verifyResponse['error']['message'] ?? 'ترب‌پی: تایید پرداخت ناموفق بود.',
                $verifyResponse,
                $verifyResponse['error']['code'] ?? -1
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
            // ... (لاگ خطا)
            throw new PaymentFailedException(
                'ترب‌پی: تسویه انجام نشد. لطفاً به صورت دستی پیگیری کنید.',
                $e->response?->json() ?? [],
                $e->response?->status() ?? -1
            );
        }

        Log::channel('payment')->info('TorobPay Settle Response', [
            'transaction_id' => $transaction->id,
            'response' => $settleResponse,
        ]);

        // ✅ بررسی صحیح: از کلید 'successful' استفاده کن
        if (!($settleResponse['successful'] ?? false)) {
            throw new PaymentFailedException(
                $settleResponse['error']['message'] ?? 'ترب‌پی: تسویه ناموفق بود.',
                $settleResponse,
                $settleResponse['error']['code'] ?? -1
            );
        }

        // ذخیره اطلاعات در تراکنش
        $transaction->update([
            'verify_data' => [
                'verify' => $verifyResponse,
                'settle' => $settleResponse,
            ],
        ]);

        // استخراج transactionId از پاسخ settle (با احتیاط)
        $refId = $settleResponse['response']['transactionId']
            ?? $verifyResponse['response']['transactionId']
            ?? $paymentToken;

        return [
            'success' => true,
            'ref_id' => $refId,
            'response' => $settleResponse,
        ];
    }
}
