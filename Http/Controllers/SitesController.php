<?php

namespace Modules\WordPressChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mailbox;
use Illuminate\Http\Request;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Services\Origin;

class SitesController extends Controller
{
    protected function authorizeAdmin()
    {
        if (!auth()->user() || !auth()->user()->isAdmin()) {
            abort(403);
        }
    }

    public function index()
    {
        $this->authorizeAdmin();

        return view('wordpresschat::sites.index', [
            'sites' => Site::with('mailbox')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        $this->authorizeAdmin();

        return view('wordpresschat::sites.form', [
            'site'      => new Site(['fallback_delay' => 120, 'max_attachment_mb' => 10]),
            'mailboxes' => Mailbox::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin();

        $data = $this->validated($request);

        $site = new Site($data);
        $site->public_id = bin2hex(random_bytes(16));
        $site->setSecret(self::newSecret());
        $site->save();

        \Session::flash('flash_success_floating', __('Website added'));

        return redirect()->route('wordpresschat.sites.edit', ['id' => $site->id]);
    }

    public function edit($id)
    {
        $this->authorizeAdmin();

        return view('wordpresschat::sites.form', [
            'site'      => Site::findOrFail($id),
            'mailboxes' => Mailbox::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeAdmin();

        $site = Site::findOrFail($id);
        $site->fill($this->validated($request));
        $site->save();

        \Session::flash('flash_success_floating', __('Website saved'));

        return redirect()->route('wordpresschat.sites.edit', ['id' => $site->id]);
    }

    public function destroy($id)
    {
        $this->authorizeAdmin();

        Site::findOrFail($id)->delete();

        \Session::flash('flash_success_floating', __('Website deleted'));

        return redirect()->route('wordpresschat.sites.index');
    }

    public function regenerateSecret($id)
    {
        $this->authorizeAdmin();

        $site = Site::findOrFail($id);
        $site->setSecret(self::newSecret());
        $site->save();

        \Session::flash('flash_success_floating', __('Secret regenerated. Update it in the WordPress plugin settings.'));

        return redirect()->route('wordpresschat.sites.edit', ['id' => $site->id]);
    }

    /**
     * Validate and return the editable columns (never public_id or secret).
     */
    protected function validated(Request $request)
    {
        $this->validate($request, [
            'name'              => 'required|max:100',
            'url'               => 'required|max:255',
            'mailbox_id'        => 'required|exists:mailboxes,id',
            'fallback_delay'    => 'nullable|integer|min:30|max:3600',
            'max_attachment_mb' => 'nullable|integer|min:1|max:50',
        ]);

        $origin = Origin::normalize($request->input('url'));
        if ($origin === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'url' => [__('Enter the website\'s address, e.g. https://www.example.com')],
            ]);
        }

        return [
            'name'              => $request->input('name'),
            'url'               => $request->input('url'),
            'origin'            => $origin,
            'mailbox_id'        => (int) $request->input('mailbox_id'),
            'fallback_delay'    => $request->filled('fallback_delay') ? (int) $request->input('fallback_delay') : 120,
            'max_attachment_mb' => $request->filled('max_attachment_mb') ? (int) $request->input('max_attachment_mb') : 10,
        ];
    }

    protected static function newSecret()
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
