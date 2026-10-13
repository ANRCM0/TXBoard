<?php

namespace App\Domains\InternalReadOnly;

use App\Core\Http\TxapiResponse;
use App\Domains\Network\NetworkGroupReader;
use Illuminate\Http\Request;

final class InternalAdminNetworkGroupsDispatcher
{
    public function execute(Request $request, \stdClass $input)
    {
        return app(InternalAdminAccess::class)->run($request, $input, function (Request $request) use ($input) {
            try {
                $data = app(NetworkGroupReader::class)->read($input->page, $input->per_page);
                $data['items'] = array_map(static function (array $row): array {
                    $id = (string) $row['id'];
                    if (!preg_match('/\A[1-9][0-9]{0,9}\z/D', $id) || (int) $id > 2147483647 || !is_string($row['name'])) {
                        throw new \RuntimeException('Invalid native group');
                    }
                    foreach (['users_count', 'plans_count', 'server_count'] as $key) {
                        if (!is_int($row[$key]) || $row[$key] < 0 || $row[$key] > 9007199254740991) {
                            throw new \RuntimeException('Invalid native count');
                        }
                    }
                    foreach (['created_at', 'updated_at'] as $key) {
                        if ($row[$key] !== null && (!is_int($row[$key]) || $row[$key] < 0 || $row[$key] > 9007199254740991)) {
                            throw new \RuntimeException('Invalid native date');
                        }
                    }
                    return ['id' => $id, 'name' => $row['name'], 'users_count' => $row['users_count'],
                        'plans_count' => $row['plans_count'], 'server_count' => $row['server_count'],
                        'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
                }, $data['items']);
            } catch (\Throwable) {
                throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
            }
            return TxapiResponse::success($request, $data,
                ['current_only' => true, 'slice' => 'internal-admin-network-groups/1']);
        });
    }
}
