<?php

declare(strict_types=1);

const STORE_DESIGN_IMAGES = ['logo', 'hero_image', 'banner_image'];
const STORE_DESIGN_TEXT_LIMITS = [
    'hero_title' => 120,
    'hero_description' => 500,
    'banner_title' => 120,
    'banner_description' => 500,
];

function default_store_design(): array
{
    return [
        'logo' => '',
        'hero_image' => '',
        'banner_image' => '',
        'hero_title' => "The pieces\nwe keep close.",
        'hero_description' => 'Explore jewellery for every day, special occasions and thoughtful gifts.',
        'banner_title' => 'Made for your moments.',
        'banner_description' => 'Explore the jewellery collection.',
    ];
}

function validate_store_design(array $input): array
{
    $design = default_store_design();
    foreach (STORE_DESIGN_IMAGES as $key) {
        $name = $input[$key] ?? '';
        if (!is_string($name) || ($name !== '' && !preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/', $name))) {
            throw new DomainException('Choose a valid uploaded image for the design.');
        }
        $design[$key] = $name;
    }

    foreach (STORE_DESIGN_TEXT_LIMITS as $key => $limit) {
        $value = $input[$key] ?? $design[$key];
        if (!is_string($value) || strlen($value) > $limit * 4 + 128) {
            throw new DomainException('Keep design headings within 120 characters and descriptions within 500 characters.');
        }
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value), " \t\r\n");
        $length = preg_match_all('/./us', $value);
        if ($length === false || $length > $limit || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $value)) {
            throw new DomainException('Keep design headings within 120 characters and descriptions within 500 characters.');
        }
        $design[$key] = $value;
    }

    if (preg_match('/\A\s*\z/u', $design['hero_title'])) {
        throw new DomainException('Enter a hero heading.');
    }
    if ($design['banner_image'] !== '' && preg_match('/\A\s*\z/u', $design['banner_title'])) {
        throw new DomainException('Enter a heading for the middle banner.');
    }
    return $design;
}

function read_store_design(): array
{
    $path = store_private_directory() . '/design.json';
    if (!is_file($path)) {
        return default_store_design();
    }

    $contents = file_get_contents($path);
    if ($contents === false || strlen($contents) > 16384) {
        throw new RuntimeException('Could not read the store design.');
    }

    try {
        $design = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($design) || array_is_list($design)) {
            throw new DomainException('Invalid store design.');
        }
        return validate_store_design($design);
    } catch (JsonException | DomainException $error) {
        throw new RuntimeException('Could not read the store design.', 0, $error);
    }
}

function store_design_version(array $design): string
{
    return hash('sha256', json_encode(validate_store_design($design), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}

function save_store_design(array $input, string $expectedVersion): void
{
    $design = validate_store_design($input);
    foreach (STORE_DESIGN_IMAGES as $key) {
        if ($design[$key] !== '' && !store_photo_exists($design[$key])) {
            throw new DomainException('An uploaded design image is unavailable. Choose the image again.');
        }
    }

    $directory = store_private_directory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the private store directory.');
    }

    $lock = fopen($directory . '/design.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Could not open the design lock.');
    }

    $temporary = false;
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock the store design.');
        }
        if (!hash_equals(store_design_version(read_store_design()), $expectedVersion)) {
            throw new DomainException('The design changed since you opened this page. Review the latest version and try again.');
        }

        $temporary = tempnam($directory, '.design-');
        if ($temporary === false || !chmod($temporary, 0600)) {
            throw new RuntimeException('Could not prepare the design file.');
        }
        $contents = json_encode($design, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        if (file_put_contents($temporary, $contents) !== strlen($contents)
            || !rename($temporary, $directory . '/design.json')) {
            throw new RuntimeException('Could not save the store design.');
        }
        $temporary = false;
    } finally {
        if (is_string($temporary)) {
            @unlink($temporary);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
