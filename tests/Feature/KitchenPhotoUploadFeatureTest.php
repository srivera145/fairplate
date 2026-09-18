<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\MenuItem;
use Keel\App\Services\ImageService;
use Tests\Support\KitchenFixtures;
use Tests\TestCase;

/**
 * Menu photos: resized to fit 800px and re-encoded as WebP.
 *
 * These go through ImageService::storeFile rather than the HTTP route, because
 * the upload path's first act is is_uploaded_file(), which by design cannot be
 * true for a file a test wrote. What the route adds on top of this is the
 * upload checks and the scope check, and the scope check has its own test.
 */
class KitchenPhotoUploadFeatureTest extends TestCase
{
    use KitchenFixtures;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!ImageService::isAvailable()) {
            self::markTestSkipped('GD with WebP support is not enabled in this PHP.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            ImageService::delete($path);
        }

        parent::tearDown();
    }

    public function testATallPhotoIsScaledToFitTheBoxAndKeepsItsProportions(): void
    {
        $source = $this->sourceImage(2400, 1600);

        $stored = $this->store($source, 'menu/test');

        [$width, $height] = getimagesize($this->absolute($stored));

        self::assertSame(ImageService::MAX_EDGE, $width);
        self::assertSame(533, $height, '2400x1600 scaled to fit 800 on the long edge');
    }

    public function testAPortraitPhotoIsBoundedByItsHeight(): void
    {
        $stored = $this->store($this->sourceImage(1000, 2000), 'menu/test');

        [$width, $height] = getimagesize($this->absolute($stored));

        self::assertSame(400, $width);
        self::assertSame(ImageService::MAX_EDGE, $height);
    }

    public function testEverythingComesOutAsWebp(): void
    {
        $stored = $this->store($this->sourceImage(1200, 900), 'menu/test');

        self::assertStringEndsWith('.webp', $stored);
        self::assertSame(IMAGETYPE_WEBP, getimagesize($this->absolute($stored))[2]);
    }

    /**
     * Re-encoding a photo that is already small only loses detail, so it is
     * left at its own size — still converted, just not scaled.
     */
    public function testASmallPhotoIsNotEnlarged(): void
    {
        $stored = $this->store($this->sourceImage(320, 240), 'menu/test');

        [$width, $height] = getimagesize($this->absolute($stored));

        self::assertSame(320, $width);
        self::assertSame(240, $height);
    }

    public function testTheStoredPathIsServableAndUnderUploads(): void
    {
        $stored = $this->store($this->sourceImage(900, 900), 'menu/42');

        self::assertMatchesRegularExpression('#^/uploads/menu/42/[0-9a-f]{32}\.webp$#', $stored);
        self::assertFileExists($this->absolute($stored));
    }

    /**
     * The extension is ignored entirely; the type comes from the file's own
     * header. A PHP script named .jpg never reaches the public web root.
     */
    public function testAFileThatIsNotAnImageIsRefused(): void
    {
        $path = sys_get_temp_dir() . '/fairplate-not-an-image.jpg';
        file_put_contents($path, "<?php echo 'hello'; ?>");

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('not a JPEG, PNG, GIF or WebP');

            ImageService::storeFile($path, 'menu/test');
        } finally {
            @unlink($path);
        }
    }

    public function testDeleteRemovesTheFile(): void
    {
        $stored = ImageService::storeFile($this->sourceImage(400, 400), 'menu/test');
        $absolute = $this->absolute($stored);

        self::assertFileExists($absolute);

        ImageService::delete($stored);

        self::assertFileDoesNotExist($absolute);
    }

    /**
     * A tampered database value must not be able to delete application files.
     */
    public function testDeleteIgnoresAnythingOutsideTheUploadsRoot(): void
    {
        $outside = sys_get_temp_dir() . '/fairplate-should-survive.txt';
        file_put_contents($outside, 'still here');

        try {
            ImageService::delete('../../composer.json');
            ImageService::delete('/uploads/../../composer.json');
            ImageService::delete($outside);

            self::assertFileExists($outside);
            self::assertFileExists(dirname(__DIR__, 2) . '/composer.json');
        } finally {
            @unlink($outside);
        }
    }

    public function testTheItemPhotoColumnHoldsTheServablePath(): void
    {
        $zoneId = $this->createZone();
        $created = $this->createRestaurantWithOwner('Photo Kitchen', $zoneId);
        $categoryId = $this->createCategory($created['restaurant_id']);
        $itemId = $this->createItem($created['restaurant_id'], $categoryId);

        $stored = $this->store($this->sourceImage(1600, 1200), 'menu/' . $created['restaurant_id']);
        MenuItem::update($itemId, ['photo' => $stored]);

        $this->actingAsOwner($created['owner_id']);
        $body = $this->get('/kitchen/menu/items/' . $itemId)->body;

        self::assertStringContainsString('src="' . $stored . '"', $body);
    }

    private function store(string $source, string $directory): string
    {
        $stored = ImageService::storeFile($source, $directory);
        $this->written[] = $stored;

        @unlink($source);

        return $stored;
    }

    /**
     * A throwaway JPEG of the given size, written where an upload would land.
     */
    private function sourceImage(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 90, 40));

        $path = sys_get_temp_dir() . '/fairplate-source-' . bin2hex(random_bytes(6)) . '.jpg';
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        return $path;
    }

    private function absolute(string $publicPath): string
    {
        return dirname(__DIR__, 2) . '/public_html' . $publicPath;
    }
}
