<?php

namespace Tests\Feature\Signatures;

use App\Models\SigningAsset;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Firma dibujada o escrita y sello generado en el navegador: llegan como PNG transparente por el
 * mismo endpoint de activos (`POST /firmas/activos`) y con la misma validación.
 */
class CreatedAssetTest extends TestCase
{
    use RefreshDatabase;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = sys_get_temp_dir().DIRECTORY_SEPARATOR.'firmas-creadas-'.bin2hex(random_bytes(6));
        Storage::set('local', Storage::createLocalDriver(['root' => $this->disk, 'throw' => false]));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->disk);

        parent::tearDown();
    }

    public function test_drawn_signature_png_is_saved_as_transparent_png_of_its_owner(): void
    {
        [$user, $company] = $this->memberOf(role: 'operator');

        // Como lo envía `useUpload` desde la modal «Crear firma»: multipart y redirección de vuelta.
        $this->actingInCompany($user, $company)
            ->from('/firmas')
            ->post('/firmas/activos', ['type' => 'firma', 'file' => $this->drawnPng('firma-dibujada.png', 1657, 270)])
            ->assertRedirect('/firmas')
            ->assertSessionHas('success', 'Firma guardada.');

        $asset = SigningAsset::query()->withoutGlobalScopes()->sole();
        $this->assertSame($user->id, $asset->user_id);
        $this->assertSame('firma', $asset->type);
        $this->assertSame('image/png', $asset->mime_type);
        $this->assertSame('firma-dibujada.png', $asset->name);
        $this->assertSame([1657, 270], [$asset->width, $asset->height]);
        $this->assertLessThan(SigningAsset::MAX_KILOBYTES * 1024, $asset->size);

        // El archivo guardado conserva la transparencia del lienzo.
        $stored = imagecreatefromstring(Storage::disk('local')->get($asset->path));
        $this->assertSame(127, (imagecolorat($stored, 2, 2) >> 24) & 0x7F, 'La esquina debe seguir transparente.');
        $this->assertLessThan(10, (imagecolorat($stored, 828, 135) >> 24) & 0x7F, 'El trazo debe ser opaco.');

        $this->actingInCompany($user, $company)->get('/firmas/activos/firma/imagen')
            ->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_generated_seal_is_personal_and_other_users_never_receive_it(): void
    {
        [$owner, $company] = $this->memberOf();
        [$other] = $this->memberOf($company);

        $this->actingInCompany($owner, $company)
            ->postJson('/firmas/activos', ['type' => 'sello', 'file' => $this->drawnPng('sello-generado.png', 984, 984)])
            ->assertOk()
            ->assertJsonPath('message', 'Sello guardado.')
            ->assertJsonPath('asset.width', 984);

        $this->actingInCompany($other, $company)->get('/firmas/activos/sello/imagen')->assertNotFound();
        $this->actingInCompany($other, $company)->getJson('/firmas/estado')->assertOk()->assertJsonPath('assets.sello', null);
    }

    public function test_viewer_can_prepare_a_drawn_signature(): void
    {
        [$viewer, $company] = $this->memberOf(role: 'viewer');

        $this->actingInCompany($viewer, $company)
            ->postJson('/firmas/activos', ['type' => 'firma', 'file' => $this->drawnPng('firma-escrita.png', 1278, 176)])
            ->assertOk();

        $this->assertSame($viewer->id, SigningAsset::query()->withoutGlobalScopes()->sole()->user_id);
    }

    public function test_generated_png_keeps_the_same_limits(): void
    {
        [$user, $company] = $this->memberOf();

        $this->actingInCompany($user, $company)
            ->postJson('/firmas/activos', ['type' => 'firma', 'file' => UploadedFile::fake()->create('firma-dibujada.png', 2100, 'image/png')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->actingInCompany($user, $company)
            ->postJson('/firmas/activos', ['type' => 'firma', 'file' => $this->drawnPng('firma-dibujada.png', 12, 12)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, SigningAsset::query()->withoutGlobalScopes()->count());
    }

    public function test_page_and_state_send_the_active_company_for_the_seal_generator(): void
    {
        [$user, $company] = $this->memberOf();
        $company->forceFill(['name' => 'Suministros Lempira', 'tax_id' => '08019012345678'])->save();

        $this->actingInCompany($user, $company)->get('/firmas')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Signatures/Index')
                ->where('company.name', 'Suministros Lempira')
                ->where('company.tax_id', '08019012345678'));

        $this->actingInCompany($user, $company)->getJson('/firmas/estado')
            ->assertOk()
            ->assertJsonPath('company.name', 'Suministros Lempira')
            ->assertJsonPath('company.tax_id', '08019012345678');
    }

    /** PNG transparente con un trazo opaco en el centro, como lo exporta el lienzo. */
    private function drawnPng(string $name, int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);
        $ink = imagecolorallocate($image, 31, 58, 147);
        imagesetthickness($image, max(1, intdiv($height, 20)));
        imageline($image, intdiv($width, 10), intdiv($height, 2), $width - intdiv($width, 10), intdiv($height, 2), $ink);

        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }
}
