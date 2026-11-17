<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    private const FIRST = [
        ['ليلى', 'layla'], ['محمد', 'mohammad'], ['رهف', 'rahaf'], ['عمر', 'omar'], ['نور', 'nour'], ['سارة', 'sara'],
        ['أحمد', 'ahmad'], ['هبة', 'hiba'], ['يزن', 'yazan'], ['مايا', 'maya'], ['خالد', 'khaled'], ['ريم', 'reem'],
        ['بشر', 'bishr'], ['جود', 'joud'], ['لمى', 'lama'], ['فراس', 'firas'], ['ديما', 'dima'], ['سامي', 'sami'],
    ];

    private const LAST = [
        ['حداد', 'Haddad'], ['العلي', 'Al-Ali'], ['السيد', 'Al-Sayed'], ['قباني', 'Kabbani'], ['شامي', 'Shami'],
        ['يوسف', 'Youssef'], ['درويش', 'Darwish'], ['خوري', 'Khoury'], ['مراد', 'Murad'], ['عيسى', 'Issa'],
        ['نجار', 'Najjar'], ['الأحمد', 'Al-Ahmad'], ['سلوم', 'Salloum'], ['العمر', 'Al-Omar'], ['زيدان', 'Zeidan'],
    ];

    public function definition(): array
    {
        [$firstAr, $firstEn] = fake()->randomElement(self::FIRST);
        [$lastAr, $lastEn] = fake()->randomElement(self::LAST);
        $id = $firstEn.'_'.fake()->unique()->numerify('######');

        return [
            'university_id' => $id,
            'name_ar' => "{$firstAr} {$lastAr}",
            'name_en' => Str::ucfirst($firstEn)." {$lastEn}",
            'email' => "{$id}@svuonline.org",
        ];
    }
}
