<?php

namespace App\Enums;

enum FileKind: string
{
    case Pdf = 'pdf';
    case Slides = 'slides';
    case Video = 'video';
    case Archive = 'archive';
    case Doc = 'doc';

    public static function fromFileName(string $name): self
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => self::Pdf,
            'ppt', 'pptx', 'odp', 'key' => self::Slides,
            'mp4', 'webm', 'mkv', 'mov', 'lrec' => self::Video,
            'zip', 'rar', '7z' => self::Archive,
            default => self::Doc,
        };
    }
}
