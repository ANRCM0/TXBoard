<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailTemplate;
use App\Services\MailService;
use App\Services\MailTemplateAdminService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MailTemplateController extends Controller
{
    public function __construct(private readonly MailTemplateAdminService $defaults)
    {
    }

    public function list()
    {
        $dbTemplates = MailTemplate::all()->keyBy('name');

        $result = [];
        foreach (MailTemplate::TEMPLATES as $name => $meta) {
            $db = $dbTemplates->get($name);
            $result[] = [
                'name' => $name,
                'label' => $meta['label'],
                'customized' => $db !== null,
                'subject' => $db?->subject,
                'updated_at' => $db?->updated_at?->timestamp,
            ];
        }

        return $this->success($result);
    }

    public function get(Request $request)
    {
        // getMeta()/getDefaultSubject() take a non-nullable string, so an
        // absent `name` used to raise a TypeError and answer 500. Validate it
        // like the sibling routes do and return 422 instead.
        $params = $request->validate([
            'name' => 'required|string',
        ]);

        $name = $params['name'];
        $meta = MailTemplate::getMeta($name);
        if (!$meta) {
            return $this->fail([404, '模板不存在']);
        }

        $db = MailTemplate::where('name', $name)->first();

        return $this->success([
            'name' => $name,
            'label' => $meta['label'],
            'required_vars' => $meta['required_vars'],
            'optional_vars' => $meta['optional_vars'],
            'customized' => $db !== null,
            'subject' => $db?->subject ?? $this->defaults->getDefaultSubject($name),
            'content' => $db?->content ?? $this->defaults->getDefaultContent($name),
        ]);
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'name' => 'required|string',
            'subject' => 'required|string|max:255',
            'content' => 'required|string',
        ]);

        $meta = MailTemplate::getMeta($params['name']);
        if (!$meta) {
            return $this->fail([404, '模板不存在']);
        }

        $errors = MailTemplate::validateContent($params['name'], $params['content']);
        if (!empty($errors)) {
            return $this->fail([422, implode('; ', $errors)]);
        }

        MailTemplate::updateOrCreate(
            ['name' => $params['name']],
            ['subject' => $params['subject'], 'content' => $params['content']]
        );
        Cache::forget("mail_template:{$params['name']}");

        return $this->success(true);
    }

    public function reset(Request $request)
    {
        $name = $request->input('name');
        $meta = MailTemplate::getMeta($name);
        if (!$meta) {
            return $this->fail([404, '模板不存在']);
        }

        MailTemplate::where('name', $name)->delete();
        Cache::forget("mail_template:{$name}");
        return $this->success(true);
    }

    public function test(Request $request)
    {
        $name = $request->input('name');
        $meta = MailTemplate::getMeta($name);
        if (!$meta) {
            return $this->fail([404, '模板不存在']);
        }

        $email = $request->input('email', $request->user()->email);
        $testVars = $this->defaults->getTestVars($name);

        try {
            $log = MailService::sendEmail([
                'email' => $email,
                'subject' => $this->defaults->getTestSubject($name),
                'template_name' => $name,
                'template_value' => $testVars,
            ]);

            if ($log['error']) {
                return $this->fail([503, '测试邮件发送失败，请检查服务端邮件日志']);
            }
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([503, '测试邮件发送失败，请检查服务端邮件日志']);
        }
    }

}
