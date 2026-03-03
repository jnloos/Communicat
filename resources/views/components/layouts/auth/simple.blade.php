<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-2">
                <div class="flex flex-col items-center gap-2 font-medium">
                    <span class="flex mb-5 items-center justify-center">
                        <x-app-logo-icon class="size-28 fill-current text-black dark:text-white" />
                    </span>
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </div>
                <div class="flex flex-col gap-6">
                    {{ $slot }}
                </div>
            </div>
        </div>
        @fluxScripts
    </body>
</html>
