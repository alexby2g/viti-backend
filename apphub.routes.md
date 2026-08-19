# AppHub integration

Add this group to `routes/api.php` after reviewing the project's current API middleware/auth conventions:

```php
Route::prefix('apphub')->middleware(['auth:sanctum'])->group(function () {
    require base_path('routes/apphub.php');
});
```

The feature branch keeps route definitions isolated in `routes/apphub.php` so the existing API routes are not overwritten.
