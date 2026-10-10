<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTxSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tx_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group')->comment('设置分组')->nullable();
            $table->string('type')->comment('设置类型')->nullable();
            $table->string('name')->comment('设置名称')->unique();
            $table->string('value')->comment('设置值')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tx_settings');
    }
}
