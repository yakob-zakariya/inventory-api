<?php

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('uploads a product photo and shows its url', function () {
    actingAsOwner();
    $product = Product::factory()->create();

    $response = $this->post("/api/v1/products/{$product->id}/image", ['image' => UploadedFile::fake()->image('cement.jpg')],
        ['Accept' => 'application/json'])->assertOk();

    $path = $product->fresh()->image_path;
    expect($path)->toStartWith('products/');
    Storage::disk('public')->assertExists($path);
    $response->assertJsonPath('data.image_url', Storage::disk('public')->url($path));
    $this->getJson("/api/v1/products/{$product->id}")->assertJsonPath('data.image_url', Storage::disk('public')->url($path));
});

it('shows a null url when there is no photo', function () {
    actingAsStaff();
    $product = Product::factory()->create();

    $this->getJson("/api/v1/products/{$product->id}")->assertJsonPath('data.image_url', null);
});

it('deletes the old file when the photo is replaced', function () {
    actingAsOwner();
    $product = Product::factory()->create();
    $this->post("/api/v1/products/{$product->id}/image", ['image' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json']);
    $oldPath = $product->fresh()->image_path;

    $this->post("/api/v1/products/{$product->id}/image", ['image' => UploadedFile::fake()->image('b.webp')], ['Accept' => 'application/json'])
        ->assertOk();

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($product->fresh()->image_path);
});

it('removes the photo and its file', function () {
    actingAsOwner();
    $product = Product::factory()->create();
    $this->post("/api/v1/products/{$product->id}/image", ['image' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json']);
    $path = $product->fresh()->image_path;

    $this->deleteJson("/api/v1/products/{$product->id}/image")->assertNoContent();

    expect($product->fresh()->image_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('accepts only JPG, PNG or WebP images up to 2 MB', function (UploadedFile $file, string $message) {
    actingAsOwner();
    $product = Product::factory()->create();

    $this->post("/api/v1/products/{$product->id}/image", ['image' => $file], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors(['image' => $message]);

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
})->with([
    'a gif' => fn () => [UploadedFile::fake()->image('a.gif'), 'The image must be a JPG, PNG or WebP file.'],
    'a pdf' => fn () => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), 'The image must be a JPG, PNG or WebP file.'],
    'too big' => fn () => [UploadedFile::fake()->image('a.jpg')->size(2049), 'The image must not be larger than 2 MB.'],
]);

it('requires an image', function () {
    actingAsOwner();
    $product = Product::factory()->create();

    $this->post("/api/v1/products/{$product->id}/image", [], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors(['image']);
});

it('needs products.manage', function () {
    actingAsStaff();
    $product = Product::factory()->create();

    $this->post("/api/v1/products/{$product->id}/image", ['image' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
        ->assertForbidden();
    $this->deleteJson("/api/v1/products/{$product->id}/image")->assertForbidden();
});

it('keeps the photo of a deleted product', function () {
    actingAsOwner();
    $product = Product::factory()->create();
    $this->post("/api/v1/products/{$product->id}/image", ['image' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json']);
    $path = $product->fresh()->image_path;

    $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();

    Storage::disk('public')->assertExists($path);
});
