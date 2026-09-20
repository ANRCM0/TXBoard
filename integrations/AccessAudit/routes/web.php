<?php

use Illuminate\Support\Facades\Route;
use Plugin\AccessAudit\Http\Controllers\AdminController;
use Plugin\AccessAudit\Http\Controllers\AnalyticsController;
use Plugin\AccessAudit\Http\Controllers\DashboardController;
use Plugin\AccessAudit\Http\Controllers\SettingsController;

// 页面本体不挂 admin（页面内置管理员登录表单），数据/操作接口挂 admin
Route::middleware(['web'])->group(function () {
    Route::get('/plugin/access-audit', [DashboardController::class, 'page']);
    Route::get('/plugin/access-audit/insights', [AnalyticsController::class, 'page']);
});

Route::middleware(['web', 'admin'])->group(function () {
    Route::get('/plugin/access-audit/stats', [AdminController::class, 'stats']);
    Route::get('/plugin/access-audit/nodes', [AdminController::class, 'nodes']);
    Route::get('/plugin/access-audit/rules', [AdminController::class, 'rules']);
    Route::post('/plugin/access-audit/rules/save', [AdminController::class, 'saveRule']);
    Route::post('/plugin/access-audit/rules/delete', [AdminController::class, 'deleteRule']);

    Route::get('/plugin/access-audit/reports', [AdminController::class, 'reports']);
    Route::get('/plugin/access-audit/logs', [DashboardController::class, 'logs']);
    Route::post('/plugin/access-audit/logs/clear', [DashboardController::class, 'clearLogs']);
    Route::get('/plugin/access-audit/ban-logs', [AdminController::class, 'banLogs']);

    Route::get('/plugin/access-audit/analytics', [AnalyticsController::class, 'data']);
    Route::get('/plugin/access-audit/settings', [SettingsController::class, 'index']);
    Route::post('/plugin/access-audit/settings', [SettingsController::class, 'update']);

    Route::post('/plugin/access-audit/ban', [AdminController::class, 'ban']);
    Route::post('/plugin/access-audit/unban', [AdminController::class, 'unban']);
});
