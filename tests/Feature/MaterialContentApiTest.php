<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// MG10: a material as one rich-text page.
class MaterialContentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $token = User::factory()->manager()->create()->createToken('api')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_material_can_be_stored_with_content_only(): void
    {
        $response = $this->postJson('/api/materials', [
            'title' => 'Off-plan basics',
            'category' => 'Off-plan',
            'content' => '<p onclick="x()">Escrow protects the buyer.</p><script>alert(1)</script><div data-keypoint="">Always check the escrow account.</div>',
            'attachments' => [['name' => 'Guide.pdf', 'url' => 'https://x.test/storage/materials/pdfs/guide.pdf', 'type' => 'pdf']],
            'xp' => 30,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.content', '<p>Escrow protects the buyer.</p><div data-keypoint="">Always check the escrow account.</div>')
            ->assertJsonPath('data.summary', 'Escrow protects the buyer.')
            ->assertJsonPath('data.attachments.0.name', 'Guide.pdf')
            ->assertJsonPath('data.reading_minutes', 1)
            ->assertJsonPath('data.stages', [])
            ->assertJsonPath('data.status', 'draft');

        $this->assertStringNotContainsString('script', (string) Material::first()->content);
    }

    public function test_material_can_be_stored_with_only_attachments(): void
    {
        $this->postJson('/api/materials', [
            'title' => 'Price list',
            'category' => 'Files',
            'content' => '',
            'attachments' => [['name' => 'Prices.pdf', 'url' => 'https://x.test/storage/materials/pdfs/p.pdf', 'type' => 'pdf']],
        ])->assertCreated()
            ->assertJsonPath('data.content', null)
            ->assertJsonPath('data.summary', 'Files: Prices.pdf');
    }

    public function test_store_without_content_still_needs_summary_and_stages(): void
    {
        $this->postJson('/api/materials', ['title' => 'X', 'category' => 'Y'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['summary', 'stages']);
    }

    public function test_update_sanitizes_content_and_reading_time_is_computed(): void
    {
        $material = Material::factory()->create(['estimated_time' => null]);
        $words = implode(' ', array_fill(0, 450, 'word'));

        $this->putJson("/api/materials/{$material->id}", [
            'content' => '<p>'.$words.'</p><a href="javascript:alert(1)">bad</a>',
        ])->assertOk()
            ->assertJsonPath('data.reading_minutes', 3)
            ->assertJsonMissingPath('data.content_html');

        $this->assertStringNotContainsString('javascript', (string) $material->fresh()->content);
    }

    public function test_image_upload_accepts_images_and_rejects_other_files(): void
    {
        Storage::fake('public');

        $this->post('/api/uploads', [
            'type' => 'image',
            'file' => UploadedFile::fake()->create('photo.png', 200, 'image/png'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'image');

        $this->post('/api/uploads', [
            'type' => 'image',
            'file' => UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->post('/api/uploads', [
            'type' => 'image',
            'file' => UploadedFile::fake()->create('huge.jpg', 6000, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }
}
