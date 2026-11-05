<?php

namespace Modules\WordPressChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mailbox;
use Illuminate\Http\Request;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Services\Origin;
use Modules\WordPressChat\Services\SchemaGuard;

class SitesController extends Controller
{
    protected function authorizeAdmin()
    {
        if (!auth()->user() || !auth()->user()->isAdmin()) {
            abort(403);
        }
    }

    /**
     * Null when the schema is complete. While a module migration is
     * pending, the page that shows only the "needs a database update"
     * notice, or for a write a redirect to it: the sites table may not
     * exist yet (a first install whose migrations were skipped), and a
     * newer column may be missing, so nothing reads or writes it.
     */
    protected function schemaPending(bool $write = false)
    {
        if (SchemaGuard::ok()) {
            return null;
        }

        return $write
            ? redirect()->route('wordpresschat.sites.index')
            : view('wordpresschat::sites.schema');
    }

    public function index()
    {
        $this->authorizeAdmin();

        if ($pending = $this->schemaPending()) {
            return $pending;
        }

        return view('wordpresschat::sites.index', [
            'sites' => Site::with('mailbox')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        $this->authorizeAdmin();

        if ($pending = $this->schemaPending()) {
            return $pending;
        }

        return view('wordpresschat::sites.form', [
            'site'      => new Site(['fallback_delay' => 120, 'max_attachment_mb' => 10]),
            'secret'    => null,
            'mailboxes' => Mailbox::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin();

        if ($pending = $this->schemaPending(true)) {
            return $pending;
        }

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

        if ($pending = $this->schemaPending()) {
            return $pending;
        }

        $site = $this->findSite($id);

        // Null when the secret can't be decrypted (e.g. after an APP_KEY
        // change): the form then asks to regenerate it instead of a 500.
        return view('wordpresschat::sites.form', [
            'site'      => $site,
            'secret'    => $site->secretOrNull(),
            'mailboxes' => Mailbox::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeAdmin();

        if ($pending = $this->schemaPending(true)) {
            return $pending;
        }

        $site = $this->findSite($id);
        $site->fill($this->validated($request));
        $site->save();

        \Session::flash('flash_success_floating', __('Website saved'));

        return redirect()->route('wordpresschat.sites.edit', ['id' => $site->id]);
    }

    public function destroy($id)
    {
        $this->authorizeAdmin();

        if ($pending = $this->schemaPending(true)) {
            return $pending;
        }

        // The visitor data first: should it fail, the site is still there
        // and deleting it again finishes the job.
        $site = $this->findSite($id);
        $site->purgeVisitorData();
        $site->delete();

        \Session::flash('flash_success_floating', __('Website deleted'));

        return redirect()->route('wordpresschat.sites.index');
    }

    public function regenerateSecret($id)
    {
        $this->authorizeAdmin();

        if ($pending = $this->schemaPending(true)) {
            return $pending;
        }

        $site = $this->findSite($id);
        $site->setSecret(self::newSecret());
        $site->save();

        \Session::flash('flash_success_floating', __('Secret regenerated. Update it in the WordPress plugin settings.'));

        return redirect()->route('wordpresschat.sites.edit', ['id' => $site->id]);
    }

    /**
     * The site with this id, or a 404. Ids above the 4-byte integer range
     * never exist, and PostgreSQL rejects them as a query parameter (22003)
     * instead of matching nothing, so they are turned away before the query.
     */
    protected function findSite($id)
    {
        if (!ctype_digit((string) $id) || (int) $id > 2147483647) {
            abort(404);
        }

        return Site::findOrFail($id);
    }

    /**
     * Validate and return the editable columns (never public_id or secret).
     */
    protected function validated(Request $request)
    {
        $this->validate($request, [
            'name'              => 'required|max:100',
            'url'               => 'required|max:255',
            'mailbox_id'        => 'bail|required|integer|min:1|max:2147483647|exists:mailboxes,id',
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
