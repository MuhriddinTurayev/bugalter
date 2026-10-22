<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Contractor;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => DocumentType::Invoice,
            'direction' => 'out',
            'number' => 'INV-'.fake()->unique()->numerify('2026-####'),
            'contractor_id' => Contractor::factory(),
            'issue_date' => today(),
            'due_date' => today()->addDays(10),
            'status' => DocumentStatus::Draft,
            'currency' => 'UZS',
        ];
    }

    public function status(DocumentStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
