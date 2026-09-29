<?php

namespace App\Support;

/**
 * Catalogue of seating layouts and equipment a training room can offer.
 */
final class RoomCatalog
{
    /** layout => [ar, en] */
    public const LAYOUTS = [
        'classroom' => ['صفوف دراسية', 'Classroom rows'],
        'theatre' => ['مسرحي', 'Theatre'],
        'u_shape' => ['حرف U', 'U-shape'],
        'boardroom' => ['طاولة اجتماعات', 'Boardroom'],
        'cluster' => ['مجموعات', 'Cluster / group tables'],
        'banquet' => ['طاولات دائرية', 'Banquet rounds'],
        'exam' => ['اختبار', 'Exam'],
        'open' => ['مفتوح', 'Open space'],
    ];

    /** equipment key => [ar, en, group] */
    public const EQUIPMENT = [
        'projector' => ['جهاز عرض (داتا شو)', 'Projector (data show)', 'display'],
        'smart_board' => ['سبورة ذكية', 'Smart board', 'display'],
        'screen' => ['شاشة عرض', 'Display screen', 'display'],
        'whiteboard' => ['سبورة بيضاء', 'Whiteboard', 'writing'],
        'markers' => ['أقلام سبورة', 'Board markers', 'writing'],
        'flipchart' => ['لوح قلاب', 'Flip chart', 'writing'],
        'laser_pointer' => ['مؤشر ليزر', 'Laser pointer', 'writing'],
        'sound_system' => ['نظام صوت', 'Sound system', 'audio_video'],
        'microphone' => ['ميكروفون', 'Microphone', 'audio_video'],
        'camera' => ['كاميرا', 'Camera', 'audio_video'],
        'recording' => ['تسجيل الجلسات', 'Session recording', 'audio_video'],
        'video_conference' => ['اتصال مرئي', 'Video conferencing', 'audio_video'],
        'computers' => ['أجهزة حاسوب', 'Computers', 'tech'],
        'laptops' => ['حواسيب محمولة', 'Laptops', 'tech'],
        'tablets' => ['أجهزة لوحية', 'Tablets', 'tech'],
        'wifi' => ['شبكة لاسلكية', 'Wi-Fi', 'tech'],
        'printer' => ['طابعة', 'Printer', 'tech'],
        'vr' => ['واقع افتراضي', 'VR headsets', 'tech'],
        '3d_printer' => ['طابعة ثلاثية الأبعاد', '3D printer', 'tech'],
        'power_outlets' => ['مقابس كهرباء', 'Power outlets', 'comfort'],
        'air_conditioning' => ['تكييف', 'Air conditioning', 'comfort'],
        'refreshments' => ['ركن ضيافة', 'Refreshments area', 'comfort'],
        'prayer_space' => ['مصلى قريب', 'Nearby prayer space', 'comfort'],
    ];

    public const ROOM_STATUSES = ['active', 'maintenance', 'inactive'];

    public static function layoutKeys(): array
    {
        return array_keys(self::LAYOUTS);
    }

    public static function equipmentKeys(): array
    {
        return array_keys(self::EQUIPMENT);
    }

    public static function options(): array
    {
        return [
            'layouts' => collect(self::LAYOUTS)->map(fn ($l, $k) => ['key' => $k, 'ar' => $l[0], 'en' => $l[1]])->values(),
            'equipment' => collect(self::EQUIPMENT)->map(fn ($e, $k) => ['key' => $k, 'ar' => $e[0], 'en' => $e[1], 'group' => $e[2]])->values(),
            'statuses' => self::ROOM_STATUSES,
        ];
    }
}
