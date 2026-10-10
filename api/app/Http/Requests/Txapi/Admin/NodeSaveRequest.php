<?php

namespace App\Http\Requests\Txapi\Admin;

use App\Http\Requests\Admin\ServerSave;

final class NodeSaveRequest extends ServerSave
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['required', 'string', 'max:255'];
        $rules['host'] = ['required', 'string', 'max:255'];
        $rules['port'] = ['required', 'regex:/^[0-9]{1,5}(?:-[0-9]{1,5})?$/'];
        $rules['server_port'] = ['required', 'integer', 'between:1,65535'];
        $rules['rate'] = ['required', 'numeric', 'min:0'];
        $rules['show'] = ['sometimes', 'boolean'];
        $rules['group_ids'] = ['nullable', 'array', 'max:100'];
        $rules['group_ids.*'] = ['integer', 'distinct', \Illuminate\Validation\Rule::exists(\App\Support\Database\NativeTableName::runtime('v2_server_group'), 'id')];
        $rules['route_ids'] = ['nullable', 'array', 'max:200'];
        $rules['route_ids.*'] = ['integer', 'distinct', \Illuminate\Validation\Rule::exists(\App\Support\Database\NativeTableName::runtime('v2_server_route'), 'id')];
        $rules['machine_id'] = ['nullable', 'integer', \Illuminate\Validation\Rule::exists(\App\Support\Database\NativeTableName::runtime('v2_server_machine'), 'id')];
        $rules['parent_id'] = ['nullable', 'integer', \Illuminate\Validation\Rule::exists(\App\Support\Database\NativeTableName::runtime('v2_server'), 'id')];
        $rules['tags'] = ['nullable', 'array', 'max:100'];
        $rules['tags.*'] = ['string', 'max:100'];
        $rules['transfer_enable'] = ['nullable', 'integer', 'min:0'];
        return $rules;
    }
}
