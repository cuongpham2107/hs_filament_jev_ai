<?php

namespace Database\Factories;

use App\Models\TraCuuLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TraCuuLog> */
class TraCuuLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'message' => 'NAN HOA & CHÂN NAN HOA DÀNH CHO XE MÁY (SPOKES & NIPPLES FOR MOTORCYCLE)',
            'status' => 'ok',
            'ma_hs' => '87141020',
            'do_tin_cay' => fake()->randomFloat(3, 0.7, 1),
            'can_cu' => 'Đường đi: Nhóm 87.14 (95%) › Của mô tô (84%) › Nan hoa và ốc bắt đầu nan hoa (100%)',
            'the_ket_qua' => "### 2. Mã HS\n8714.10.20",
            'duration_ms' => fake()->numberBetween(2000, 9000),
        ];
    }

    public function needsMoreInfo(): static
    {
        return $this->state(['status' => 'can_bo_sung', 'message' => 'ÁO PHÔNG DỆT KIM 100% COTTON', 'ma_hs' => '61091010', 'do_tin_cay' => 0.0]);
    }
}
