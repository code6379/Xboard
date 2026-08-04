<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_subscription_mask_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('用户 ID');
            $table->string('email', 64)->index()->comment('用户邮箱快照');
            $table->uuid('user_uuid')->nullable()->comment('用户 UUID 快照');
            $table->unsignedBigInteger('plan_id')->nullable()->index()->comment('套餐 ID 快照');
            $table->unsignedBigInteger('group_id')->nullable()->index()->comment('用户组 ID 快照');
            $table->unsignedBigInteger('transfer_enable')->nullable()->comment('总流量快照');
            $table->unsignedBigInteger('upload')->default(0)->comment('已用上行流量快照');
            $table->unsignedBigInteger('download')->default(0)->comment('已用下行流量快照');
            $table->unsignedInteger('speed_limit')->nullable()->comment('限速快照');
            $table->unsignedInteger('device_limit')->nullable()->comment('设备限制快照');
            $table->boolean('banned')->default(false)->comment('封禁状态快照');
            $table->unsignedInteger('expired_at')->nullable()->comment('到期时间戳快照');
            $table->string('ip', 128)->nullable()->comment('请求 IP');
            $table->text('forwarded_for')->nullable()->comment('X-Forwarded-For');
            $table->string('real_ip', 128)->nullable()->comment('X-Real-IP');
            $table->string('continent', 128)->nullable()->comment('IP 洲');
            $table->string('country_code', 8)->nullable()->index()->comment('IP 国家代码');
            $table->string('country', 128)->nullable()->comment('IP 国家');
            $table->string('region', 128)->nullable()->comment('IP 省份或地区');
            $table->string('city', 128)->nullable()->comment('IP 城市');
            $table->string('isp', 255)->nullable()->comment('IP 运营商');
            $table->string('as_name', 255)->nullable()->comment('IP AS 名称');
            $table->string('asn', 64)->nullable()->comment('IP ASN');
            $table->string('ip_domain', 255)->nullable()->comment('IP 归属域名');
            $table->string('usage_type', 64)->nullable()->comment('IP 使用类型');
            $table->string('net_speed', 64)->nullable()->comment('IP 网络速度');
            $table->boolean('is_proxy')->nullable()->index()->comment('是否代理 IP');
            $table->string('proxy_type', 64)->nullable()->comment('代理类型');
            $table->unsignedTinyInteger('fraud_score')->nullable()->comment('IP 风险评分');
            $table->string('threat', 255)->nullable()->comment('IP 威胁类型');
            $table->string('proxy_provider', 255)->nullable()->comment('代理服务商');
            $table->string('proxy_last_seen', 64)->nullable()->comment('代理最后出现时间');
            $table->text('risk_flags')->nullable()->comment('IP 风险标记');
            $table->string('route', 128)->nullable()->comment('路由名称');
            $table->string('request_host', 255)->nullable()->comment('请求域名');
            $table->string('request_method', 10)->nullable()->comment('请求方法');
            $table->text('requested_types')->nullable()->comment('请求节点类型');
            $table->text('filter_keyword')->nullable()->comment('请求筛选词');
            $table->text('client_flag')->nullable()->comment('客户端标记');
            $table->text('user_agent')->nullable()->comment('客户端标识');
            $table->text('referer')->nullable()->comment('请求来源页');
            $table->string('fake_domain', 255)->nullable()->comment('当前假域名');
            $table->boolean('masked')->default(false)->comment('是否替换为假域名');
            $table->string('reason', 32)->nullable()->comment('命中原因');
            $table->string('matched_value', 255)->nullable()->comment('命中内容');
            $table->boolean('completed')->default(false)->comment('伪装判断是否完成');
            $table->unsignedInteger('processing_ms')->nullable()->comment('处理耗时毫秒');
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at'], 'idx_subscription_mask_logs_user_created');
            $table->index(['ip', 'created_at'], 'idx_subscription_mask_logs_ip_created');
            $table->index(['masked', 'created_at'], 'idx_subscription_mask_logs_masked_created');
            $table->index(['reason', 'created_at'], 'idx_subscription_mask_logs_reason_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscription_mask_logs');
    }
};
