<?php

/***
 * Configuration file for translucent/s3-observer.
 * You can set these configs to each models and fields.
 *
 * ```
 * // Model settings
 * $observer = S3Observer::setUp('User', array(
 *     'bucket' => 'user-bucket',
 * ));
 *
 * $observer->setFields('profile_image', 'cover_image');
 *
 * // Field settings
 * $observer->config('profile_image.base', 'profile');
 *
 * User::observer($observer);
 * ```
 *
 */
return array(

    /**
     * Shorthand to set ACL
     * If true, the ACL of uploaded file is public-read, else private.
     */
    'public' => true,

    /**
     * Bucket of S3 to upload.
     */
    'bucket' => '',

    /**
     * Base of object url.
     */
    'base' => null,

    /**
     * S3 ACL
     * null | private | public-read | public-read-write | authenticated-read | bucket-owner-read | bucket-owner-full-control
     */
    'acl' => null,

);