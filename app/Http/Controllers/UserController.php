<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Navigation;
use App\Support\StaffPresets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Section 9: Users — CRUD, admin only, with per-user permission checkboxes.
 * The role sets the defaults; the admin then adds or removes individually.
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        return view('users.index', [
            'users' => User::withCount('permissions')
                ->orderBy('name')
                ->paginate($request->user()->items_per_page),
        ]);
    }

    public function create(): View
    {
        return view('users.create', [
            'user' => new User(['role' => User::ROLE_USER, 'is_active' => true, 'items_per_page' => 25]),
            'groups' => $this->permissionGroups(),
            'selected' => Permission::whereIn('key', User::DEFAULT_PERMISSIONS)->pluck('id')->all(),
            'presets' => $this->presets(),
            'menu' => Navigation::groups(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->rules($request);

        // Section 4: creating a user seeds the default set. Any explicit
        // selection from the form wins over it.
        $permissions = $request->has('permissions')
            ? $request->array('permissions')
            : Permission::whereIn('key', User::DEFAULT_PERMISSIONS)->pluck('id')->all();

        if ($problem = $this->refuseCostContradictions($request, $data['cost_visibility'], $permissions)) {
            return back()->withInput()->with('error', $problem);
        }

        DB::transaction(function () use ($data, $permissions) {
            $user = User::create($data);

            $this->syncPermissions($user, $permissions);
        });

        return redirect()->route('users.index')->with('success', __('User saved'));
    }

    public function edit(User $user): View
    {
        return view('users.edit', [
            'user' => $user,
            'groups' => $this->permissionGroups(),
            'selected' => $user->permissions()->pluck('permissions.id')->all(),
            'presets' => $this->presets(),
            'menu' => Navigation::groups(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->rules($request, $user);

        // The shop has to keep a way back in. An admin who demotes or
        // deactivates themselves has just closed the only screen that could
        // undo it, and on a one-admin shop that is the whole system locked.
        if ($user->is($request->user())
            && ($data['role'] !== User::ROLE_ADMIN || ! ($data['is_active'] ?? false))) {
            return back()
                ->withInput()
                ->with('error', __('You cannot take away your own admin access.'));
        }

        if ($problem = $this->refuseCostContradictions($request, $data['cost_visibility'], $request->array('permissions'))) {
            return back()->withInput()->with('error', $problem);
        }

        DB::transaction(function () use ($request, $user, $data) {
            if (blank($data['password'] ?? null)) {
                unset($data['password']);
            }

            $user->update($data);

            $this->syncPermissions($user, $request->array('permissions'));
        });

        return redirect()->route('users.index')->with('success', __('User saved'));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', __('You cannot delete your own account here.'));
        }

        // Section 5: users are always `restrict` — a deleted employee would
        // erase who made every document. Soft delete keeps the row.
        $user->delete();

        return back()->with('success', __('User deleted'));
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)->withoutTrashed()],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])],
            // Absent means the real cost, which is what everybody had before
            // the setting existed.
            'cost_visibility' => ['nullable', Rule::in([User::COST_REAL, User::COST_MARKUP, User::COST_HIDDEN])],
            'cost_markup_percent' => ['nullable', 'integer', 'min:0', 'max:500'],
            'is_active' => ['boolean'],
            'language' => ['required', Rule::in(array_keys(\App\Http\Middleware\SetUserPreferences::LANGUAGES))],
            'theme' => ['required', Rule::in(['light', 'dark', 'auto'])],
            'items_per_page' => ['required', 'integer', 'min:5', 'max:200'],
        ]);

        $data['cost_visibility'] ??= User::COST_REAL;

        $data['cost_markup_percent'] = $data['cost_visibility'] === User::COST_MARKUP
            ? (int) ($data['cost_markup_percent'] ?? 0)
            : 0;

        return $data;
    }

    /**
     * The few keys nobody can hold alongside a cost they are not shown.
     *
     * Kept as short as it can honestly be. Everywhere a cost merely appears, it
     * is masked instead — including the fields on the product form, which show
     * this reader the mask and post nothing, so they keep the catalogue without
     * ever seeing or overwriting what a thing cost. Making stock adjustments is
     * theirs too: nothing on that form is filled in from what is stored.
     *
     * What is left is the purchase side, where the document *is* the cost and
     * there would be nothing to read once it was masked — and where the cart
     * opens each line at the product's purchase price, so a marked-up figure
     * would be saved back as the real one. Plus the two screens that arrive
     * pre-filled from what is stored, and the reports.
     *
     * Refused here, on the form, rather than left as a trap that looks like it
     * is working.
     *
     * @param  list<int>  $permissionIds
     */
    private function refuseCostContradictions(Request $request, string $visibility, array $permissionIds): ?string
    {
        if ($visibility === User::COST_REAL || $request->input('role') === User::ROLE_ADMIN) {
            return null;
        }

        $keys = Permission::whereIn('id', $permissionIds)->pluck('key');

        $clashes = $keys->intersect([
            // A purchase document is what the shop paid, from the first line to
            // the total — there is nothing left of it once the costs are
            // masked. The cart even opens each line at the product's purchase
            // price, so a marked-up figure would be saved back as the real one.
            'purchases.view', 'purchases.create', 'purchases.edit',
            'purchase_returns.view',

            // Opens filled in with the cost that is already stored.
            'stock_adjustments.edit',

            // The shop's own accounts, and not masked.
            'reports.view',
        ]);

        if ($clashes->isEmpty()) {
            return null;
        }

        return __('These screens are what the shop paid, and masking them would leave nothing to read or would save a marked-up figure back as the real one. Give them the real cost, or take away: :keys', [
            'keys' => $clashes->implode(', '),
        ]);
    }

    /**
     * The starting points, with the permission ids the checkboxes actually use.
     *
     * @return list<array{label: string, note: string, ids: list<int>}>
     */
    private function presets(): array
    {
        $ids = Permission::pluck('id', 'key');

        return collect(StaffPresets::resolved($ids->keys()->all()))
            ->map(fn (array $preset) => [
                'label' => $preset['label'],
                'note' => $preset['note'],
                'ids' => collect($preset['keys'])->map(fn (string $key) => $ids[$key] ?? null)
                    ->filter()->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Set somebody's permissions, and say so in the record.
     *
     * Section 4: never consulted for admin accounts, so an admin carries no
     * permission rows at all.
     *
     * The activity log follows a model's own columns, and permissions are not
     * columns — they are rows in another table. So the one change most worth a
     * record of on this screen, who was given what, left no trace at all. This
     * writes it as a sentence: the log has no shape for a set of keys, and a
     * sentence is what somebody reading it wants anyway.
     *
     * @param  list<int>  $permissionIds
     */
    private function syncPermissions(User $user, array $permissionIds): void
    {
        $before = $user->permissions()->pluck('key')->sort()->values();

        $user->permissions()->sync($user->isAdmin() ? [] : $permissionIds);

        $after = $user->permissions()->pluck('key')->sort()->values();

        $added = $after->diff($before);
        $removed = $before->diff($after);

        if ($added->isEmpty() && $removed->isEmpty()) {
            return;
        }

        $said = collect([
            $added->isNotEmpty() ? __('Added :keys', ['keys' => $added->implode(', ')]) : null,
            $removed->isNotEmpty() ? __('Removed :keys', ['keys' => $removed->implode(', ')]) : null,
        ])->filter()->implode('. ');

        app(ActivityLogger::class)->logModel('update', $user, $said);
    }

    /**
     * The checkboxes an admin may tick.
     *
     * `users.*` is left out. This screen is admin-only at the router (see
     * EnsureAdmin), so ticking users.view for a member of staff would promise
     * a screen they still could not open — and if it did open, whoever holds
     * it could save themselves role = admin. A checkbox that either does
     * nothing or hands over the shop should not be on the page.
     *
     * Rows already granted disappear on the next save: sync() is given what
     * the form posted, and the form no longer posts them.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Permission>>
     */
    private function permissionGroups()
    {
        return Permission::whereNot('group', 'users')
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->groupBy('group');
    }
}
