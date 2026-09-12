<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;

final class PageController
{
    public function home(): void
    {
        if (\App\Core\Auth::check()) {
            header('Location: ' . \App\Core\Rbac::baseUrl() . '/dashboard');
            return;
        }
        View::render('home/landing', [], null);
    }

    public function terms(): void
    {
        View::render('home/terms', [], null);
    }

    public function notFound(): void
    {
        View::render('errors/error', ['code' => 404, 'message' => 'Page not found'], 'app');
    }

    public function forbidden(): void
    {
        View::render('errors/error', ['code' => 403, 'message' => 'You do not have permission to access this resource.'], 'app');
    }

    public function error(int $code, string $message): void
    {
        View::render('errors/error', ['code' => $code, 'message' => $message], 'app');
    }
}
