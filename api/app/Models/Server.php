<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use App\Utils\CacheKey;
use App\Utils\Helper;
use App\Models\User;
use App\Protocols\ProtocolRegistry;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * App\Models\Server
 *
 * @property int $id
 * @property string $name 节点名称
 * @property string $type 服务类型
 * @property string $host 主机地址
 * @property string|int $port 端口
 * @property int|null $server_port 服务器端口
 * @property array|null $group_ids 分组IDs
 * @property array|null $route_ids 路由IDs
 * @property array|null $tags 标签
 * @property boolean $show 是否显示
 * @property string|null $allow_insecure 是否允许不安全
 * @property string|null $network 网络类型
 * @property int|null $parent_id 父节点ID
 * @property float|null $rate 倍率
 * @property boolean $rate_time_enable 是否启用时间范围功能
 * @property array|null $rate_time_ranges 倍率时间范围
 * @property int|null $sort 排序
 * @property array|null $protocol_settings 协议设置
 * @property int $created_at
 * @property int $updated_at
 * 
 * @property-read Server|null $parent 父节点
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StatServer> $stats 节点统计
 * 
 * @property-read int|null $last_check_at 最后检查时间（Unix时间戳）
 * @property-read int|null $last_push_at 最后推送时间（Unix时间戳）
 * @property-read int $online 在线用户数
 * @property-read int $online_conn 在线连接数
 * @property-read array|null $metrics 节点指标指标
 * @property-read int $is_online 是否在线（1在线 0离线）
 * @property-read string $available_status 可用状态描述
 * @property-read string $cache_key 缓存键
 * @property string|null $ports 端口范围
 * @property string|null $password 密码
 * @property int|null $u 上行流量
 * @property int|null $d 下行流量
 * @property int|null $total 总流量
 * @property-read array|null $load_status 负载状态（包含CPU、内存、交换区、磁盘信息）
 * 
 * @property int $transfer_enable 流量上限，0或者null表示不限制
 * @property int $u 当前上传流量
 * @property int $d 当前下载流量
 */
class Server extends Model
{
    public const TYPE_HYSTERIA = 'hysteria';
    public const TYPE_VLESS = 'vless';
    public const TYPE_TROJAN = 'trojan';
    public const TYPE_VMESS = 'vmess';
    public const TYPE_TUIC = 'tuic';
    public const TYPE_SHADOWSOCKS = 'shadowsocks';
    public const TYPE_ANYTLS = 'anytls';
    public const TYPE_SOCKS = 'socks';
    public const TYPE_NAIVE = 'naive';
    public const TYPE_HTTP = 'http';
    public const TYPE_MIERU = 'mieru';
    public const STATUS_OFFLINE = 0;
    public const STATUS_ONLINE_NO_PUSH = 1;
    public const STATUS_ONLINE = 2;

    public const CHECK_INTERVAL = 300; // 5 minutes in seconds

    private const CIPHER_CONFIGURATIONS = [
        '2022-blake3-aes-128-gcm' => [
            'serverKeySize' => 16,
            'userKeySize' => 16,
        ],
        '2022-blake3-aes-256-gcm' => [
            'serverKeySize' => 32,
            'userKeySize' => 32,
        ],
        '2022-blake3-chacha20-poly1305' => [
            'serverKeySize' => 32,
            'userKeySize' => 32,
        ]
    ];

    public const TYPE_ALIASES = [
        'v2ray' => self::TYPE_VMESS,
        'hysteria2' => self::TYPE_HYSTERIA,
    ];

    public const VALID_TYPES = [
        self::TYPE_HYSTERIA,
        self::TYPE_VLESS,
        self::TYPE_TROJAN,
        self::TYPE_VMESS,
        self::TYPE_TUIC,
        self::TYPE_SHADOWSOCKS,
        self::TYPE_ANYTLS,
        self::TYPE_SOCKS,
        self::TYPE_NAIVE,
        self::TYPE_HTTP,
        self::TYPE_MIERU,
    ];

    protected $table = 'v2_server';

    protected $guarded = ['id'];
    protected $casts = [
        'group_ids' => 'array',
        'route_ids' => 'array',
        'tags' => 'array',
        'protocol_settings' => 'array',
        'custom_outbounds' => 'array',
        'custom_routes' => 'array',
        'cert_config' => 'array',
        'last_check_at' => 'integer',
        'last_push_at' => 'integer',
        'show' => 'boolean',
        'enabled' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'rate_time_ranges' => 'array',
        'rate_time_enable' => 'boolean',
        'transfer_enable' => 'integer',
        'u' => 'integer',
        'd' => 'integer',
        'machine_id' => 'integer',
    ];

    public function getProtocolSettingsAttribute($value)
    {
        $settings = json_decode($value, true) ?? [];
        $definition = app(ProtocolRegistry::class)->get($this->type);

        return $definition ? $definition->normalize($settings) : $settings;
    }

    public function setProtocolSettingsAttribute($value)
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        $settings = is_array($value) ? $value : [];
        $definition = app(ProtocolRegistry::class)->get($this->type);
        $normalized = $definition ? $definition->normalize($settings) : $settings;

        $this->attributes['protocol_settings'] = json_encode($normalized);
    }

    public function generateServerPassword(User $user): string
    {
        if ($this->type !== self::TYPE_SHADOWSOCKS) {
            return $user->uuid;
        }


        $cipher = data_get($this, 'protocol_settings.cipher');
        if (!$cipher || !isset(self::CIPHER_CONFIGURATIONS[$cipher])) {
            return $user->uuid;
        }

        $config = self::CIPHER_CONFIGURATIONS[$cipher];
        // Use parent's created_at if this is a child node
        $serverCreatedAt = $this->parent_id ? $this->parent->created_at : $this->created_at;
        $serverKey = Helper::getServerKey($serverCreatedAt, $config['serverKeySize']);
        $userKey = Helper::uuidToBase64($user->uuid, $config['userKeySize']);
        return "{$serverKey}:{$userKey}";
    }

    public static function normalizeType(?string $type): string | null
    {
        return $type ? strtolower(self::TYPE_ALIASES[$type] ?? $type) : null;
    }
    
    public static function isValidType(?string $type): bool
    {
        return $type ? in_array(self::normalizeType($type), self::VALID_TYPES, true) : true;
    }

    public function getAvailableStatusAttribute(): int
    {
        $now = time();
        if (!$this->last_check_at || ($now - self::CHECK_INTERVAL) >= $this->last_check_at) {
            return self::STATUS_OFFLINE;
        }
        if (!$this->last_push_at || ($now - self::CHECK_INTERVAL) >= $this->last_push_at) {
            return self::STATUS_ONLINE_NO_PUSH;
        }
        return self::STATUS_ONLINE;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    public function stats(): HasMany
    {
        return $this->hasMany(StatServer::class, 'server_id', 'id');
    }

    public function machine(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id');
    }

    public function groups()
    {
        return ServerGroup::whereIn('id', $this->group_ids ?? [])->get();
    }

    public function routes()
    {
        return ServerRoute::whereIn('id', $this->route_ids)->get();
    }

    /**
     * 最后检查时间访问器
     */
    protected function lastCheckAt(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->parent_id ?: $this->id;
                return Cache::get(CacheKey::get("SERVER_{$type}_LAST_CHECK_AT", $serverId));
            }
        );
    }

    /**
     * 最后推送时间访问器
     */
    protected function lastPushAt(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->parent_id ?: $this->id;
                return Cache::get(CacheKey::get("SERVER_{$type}_LAST_PUSH_AT", $serverId));
            }
        );
    }

    /**
     * 在线用户数访问器
     */
    protected function online(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->parent_id ?: $this->id;
                return Cache::get(CacheKey::get("SERVER_{$type}_ONLINE_USER", $serverId)) ?? 0;
            }
        );
    }

    /**
     * 是否在线访问器
     */
    protected function isOnline(): Attribute
    {
        return Attribute::make(
            get: function () {
                return (time() - 300 > $this->last_check_at) ? 0 : 1;
            }
        );
    }

    /**
     * 缓存键访问器
     */
    protected function cacheKey(): Attribute
    {
        return Attribute::make(
            get: function () {
                return "{$this->type}-{$this->id}-{$this->updated_at}-{$this->is_online}";
            }
        );
    }

    /**
     * 服务器密钥访问器
     */
    protected function serverKey(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->type === self::TYPE_SHADOWSOCKS) {
                    return Helper::getServerKey($this->created_at, 16);
                }
                return null;
            }
        );
    }

    /**
     * 指标指标访问器
     */
    protected function metrics(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->parent_id ?: $this->id;
                return Cache::get(CacheKey::get("SERVER_{$type}_METRICS", $serverId));
            }
        );
    }

    /**
     * 在线连接数访问器
     */
    protected function onlineConn(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->metrics['active_connections'] ?? 0;
            }
        );
    }

    /**
     * 负载状态访问器
     */
    protected function loadStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->parent_id ?: $this->id;
                return Cache::get(CacheKey::get("SERVER_{$type}_LOAD_STATUS", $serverId));
            }
        );
    }

    public function getCurrentRate(): float
    {
        if (!$this->rate_time_enable) {
            return (float) $this->rate;
        }

        $now = now()->format('H:i');
        $ranges = $this->rate_time_ranges ?? [];
        $matchedRange = collect($ranges)
            ->first(fn($range) => $now >= $range['start'] && $now <= $range['end']);
        
        return $matchedRange ? (float) $matchedRange['rate'] : (float) $this->rate;
    }
}
