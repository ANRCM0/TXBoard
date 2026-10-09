<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\MailTemplate;
use App\Services\MailService;
use App\Services\MailTemplateAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Admin-only mail template management backed by the same persisted overrides,
 * fallback rendering and delivery runtime used by legacy V2.
 */
final class MailTemplateAdminController
{
    public function index(Request $request): JsonResponse
    {
        $saved = MailTemplate::query()->get()->keyBy('name');
        $rows = [];
        foreach (MailTemplate::TEMPLATES as $name => $meta) {
            $entry = $saved->get($name);
            $rows[] = [
                'name' => $name,
                'label' => $meta['label'],
                'customized' => $entry !== null,
                'subject' => $entry?->subject,
                'updated_at' => $entry?->updated_at?->timestamp,
            ];
        }
        return TxapiResponse::success($request, $rows);
    }

    public function show(Request $request, MailTemplateAdminService $defaults): JsonResponse
    {
        $name = (string) $request->route('name');
        $meta = $this->knownTemplate($name);
        $saved = MailTemplate::query()->where('name', $name)->first();

        return TxapiResponse::success($request, [
            'name' => $name, 'label' => $meta['label'],
            'required_vars' => $meta['required_vars'],
            'optional_vars' => $meta['optional_vars'],
            'customized' => $saved !== null,
            'subject' => $saved?->subject ?? $defaults->getDefaultSubject($name),
            'content' => $saved?->content ?? $defaults->getDefaultContent($name),
        ])->header('Cache-Control', 'no-store');
    }

    public function save(Request $request): JsonResponse
    {
        $name = (string) $request->route('name');
        $this->knownTemplate($name);
        $input = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:200000'],
        ]);
        $errors = MailTemplate::validateContent($name, $input['content']);
        if ($errors !== []) {
            throw ValidationException::withMessages(['content' => implode('; ', $errors)]);
        }

        MailTemplate::query()->updateOrCreate(['name' => $name], $input);
        Cache::forget("mail_template:{$name}");
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function reset(Request $request): JsonResponse
    {
        $name = (string) $request->route('name');
        $this->knownTemplate($name);
        MailTemplate::query()->where('name', $name)->delete();
        Cache::forget("mail_template:{$name}");

        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function test(Request $request, MailTemplateAdminService $defaults): JsonResponse
    {
        $name = (string) $request->route('name');
        $this->knownTemplate($name);
        $input = $request->validate(['email' => ['sometimes', 'nullable', 'email:rfc', 'max:254']]);
        $email = (string) ($input['email'] ?? $request->user()->email);

        try {
            $result = MailService::sendEmail([
                'email' => $email,
                'subject' => $defaults->getTestSubject($name),
                'template_name' => $name,
                'template_value' => $defaults->getTestVars($name),
            ]);
            if (!empty($result['error'])) {
                return TxapiResponse::error($request, 'MAIL_SEND_FAILED',
                    'Test email could not be delivered', 503);
            }
        } catch (Throwable) {
            // SMTP hostnames, credentials and transport errors must not be sent
            // back to the browser or persisted in the administrator audit.
            return TxapiResponse::error($request, 'MAIL_SEND_FAILED',
                'Test email could not be delivered', 503);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }

    private function knownTemplate(string $name): array
    {
        return MailTemplate::getMeta($name) ?? abort(404, 'Unknown mail template');
    }
}
