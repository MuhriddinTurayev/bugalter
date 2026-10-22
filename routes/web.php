<?php

use Illuminate\Support\Facades\Route;

// Platform owner panel first: on the admin host its routes must win over the company routes.
require __DIR__.'/platform.php';

// Company application routes (see routes/company.php).
Route::group([], __DIR__.'/company.php');
