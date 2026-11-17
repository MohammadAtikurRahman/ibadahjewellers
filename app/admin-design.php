<?php

declare(strict_types=1);

function admin_update_design(array $input, array $files): void
{
    $current = read_store_design();
    $version = $input['version'] ?? '';
    if (!is_string($version) || !hash_equals(store_design_version($current), $version)) {
        throw new DomainException('The design changed since you opened this page. Review the latest version and try again.');
    }

    $design = $current;
    foreach (STORE_DESIGN_TEXT_LIMITS as $key => $limit) {
        $design[$key] = $input[$key] ?? '';
    }

    $uploaded = [];
    try {
        foreach (STORE_DESIGN_IMAGES as $key) {
            $file = $files[$key] ?? null;
            $remove = ($input['remove_' . $key] ?? '') === '1';
            $hasUpload = $file !== null && (!is_array($file)
                || ($file['error'] ?? null) !== UPLOAD_ERR_NO_FILE);
            if ($remove && $hasUpload) {
                throw new DomainException('Choose a replacement image or remove the current image for each position.');
            }
            if ($remove) {
                $design[$key] = '';
                continue;
            }
            $image = admin_save_product_photo($file, required: false);
            if ($image !== null) {
                $uploaded[] = $image;
                $design[$key] = $image;
            }
        }

        save_store_design($design, $version);
    } catch (Throwable $error) {
        foreach ($uploaded as $image) {
            admin_remove_product_photo($image);
        }
        throw $error;
    }

    foreach (STORE_DESIGN_IMAGES as $key) {
        $oldImage = $current[$key];
        if ($oldImage !== '' && !in_array($oldImage, $design, true)) {
            admin_remove_product_photo($oldImage);
        }
    }
}
