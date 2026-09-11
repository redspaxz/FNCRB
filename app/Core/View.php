<?php
declare(strict_types=1);

namespace App\Core;

/** Tiny view renderer with layout support and XSS-safe escaping by default. */
final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'app'): void
    {
        $data['e'] = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__) . "/Views/$template.php";
        $content = ob_get_clean();
        if ($layout) {
            require dirname(__DIR__) . "/Views/layouts/$layout.php";
        } else {
            echo $content;
        }
    }
}
