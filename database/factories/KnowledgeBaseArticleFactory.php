<?php

namespace Database\Factories;

use App\Models\KnowledgeBaseArticle;
use Illuminate\Database\Eloquent\Factories\Factory;

class KnowledgeBaseArticleFactory extends Factory
{
    protected $model = KnowledgeBaseArticle::class;

    public function definition(): array
    {
        return [
            'category' => 'faq',
            'title' => fake()->sentence(5),
            'content' => fake()->paragraph(),
            'keywords' => ['informasi', 'katalog'],
            'status' => 'active',
        ];
    }

    public function faq(): static
    {
        return $this->state(['category' => 'faq']);
    }

    public function policy(): static
    {
        return $this->state(['category' => 'policy']);
    }

    public function script(): static
    {
        return $this->state(['category' => 'script']);
    }
}
