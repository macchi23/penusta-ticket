<?php

use App\Exceptions\AllocationChangeException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AllocationChangeException $e, Request $request) {
            $body = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            if ($e->conflicts !== []) {
                $body['details'] = ['blocks' => $e->conflicts];
            }

            return response()->json($body, $e->status);
        });

        // モデル名や id を含む既定の 404 メッセージを返さない。
        // ModelNotFoundException は prepareException で NotFoundHttpException に変換されてからここに来る
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $request->is('api/*')
            ? response()->json(['code' => 'not_found', 'message' => '見つかりません。'], 404)
            : null);

        // allocation ロック待ち（lock_not_available）とデッドロック再試行枯渇は、SQL を出さずに 503 で返す
        $exceptions->render(function (QueryException $e, Request $request) {
            if (! $request->is('api/*') || ! in_array($e->getCode(), ['55P03', '40P01'], true)) {
                return null;
            }

            return response()->json(['code' => 'busy', 'message' => '混み合っています。しばらくしてからやり直してください。'], 503);
        });
    })->create();
