<?php
namespace Modules\Club\Services;

use Modules\Club\Models\UserPoint;
use Modules\Settings\Models\Setting;

class ClubLevelService
{
    /** سطوح به ترتیب صعودی */
    public function levels(): array
    {
        return [
            'bronze'   => ['min' => (int) $this->s('club.bronze_min', 0)],
            'gold'     => ['min' => (int) $this->s('club.gold_min', 500)],
            'platinum' => ['min' => (int) $this->s('club.platinum_min', 1000)],
        ];
    }

    public function levelFor(int $points): string
    {
        $level = 'bronze';
        foreach ($this->levels() as $name => $data) {
            if ($points >= $data['min']) $level = $name;
        }
        return $level;
    }

    public function syncLevel(UserPoint $row): void
    {
        $newLevel = $this->levelFor($row->total_points);
        if ($row->level !== $newLevel) {
            $row->update(['level' => $newLevel]);
        }
    }

    public function discountFor(string $level): float
    {
        return (float) $this->s("club.{$level}_discount", 0);
    }

    public function freeShippingFor(string $level): int
    {
        return (int) $this->s("club.{$level}_free_shipping", 0);
    }

    private function s(string $key, $default = null)
    {
        return optional(Setting::where('key', $key)->first())->value ?? $default;
    }
}