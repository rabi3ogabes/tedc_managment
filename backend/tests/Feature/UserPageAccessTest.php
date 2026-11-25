<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\WriteAction;
use Tests\TestCase;

/**
 * Pages of one person: which pages of the menu they are shown, and whether they may add, edit and delete in an area.
 * Hiding a page and taking an action only ever take away, and only from the person, in the role they were set in.
 */
class UserPageAccessTest extends TestCase
{
    private function grantOf(User $user, string $slug): RoleUser
    {
        return RoleUser::where('user_id', $user->id)->where('role_id', Role::where('slug', $slug)->value('id'))->firstOrFail();
    }

    /** A supervisor who manages announcements, whatever the role's own rights are today. */
    private function person(User $admin): array
    {
        $person = $this->makeUser(Role::SUPERVISOR);
        $grant = $this->grantOf($person, Role::SUPERVISOR);
        $this->limit($admin, $person, $grant, ['granted' => ['announcements.manage'], 'revoked' => []])->assertOk();

        return [$person, $grant->fresh()];
    }

    private function limit(User $admin, User $user, RoleUser $grant, array $body)
    {
        return $this->asUser($admin)->putJson("/api/v1/admin/users/{$user->id}/roles/{$grant->id}/permissions", $body + ['granted' => $grant->fresh()->granted_permissions ?? [], 'revoked' => $grant->fresh()->revoked_permissions ?? []]);
    }

    private function announcement(User $author): Announcement
    {
        return Announcement::create(['title_ar' => 'إعلان', 'title_en' => 'Notice', 'created_by' => $author->id, 'status' => 'draft']);
    }

    public function test_a_page_given_to_view_only_is_read_and_every_change_to_it_is_refused(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        [$person, $grant] = $this->person($admin);
        $notice = $this->announcement($admin);
        $draft = ['title_ar' => 'جديد', 'title_en' => 'New'];

        // With the right and no limit, the person adds, edits and deletes.
        $this->asUser($person)->postJson('/api/v1/admin/announcements', $draft)->assertCreated();
        $this->asUser($person)->putJson("/api/v1/admin/announcements/{$notice->id}", ['title_en' => 'Edited'])->assertOk();

        $this->limit($admin, $person, $grant, ['denied_actions' => ['announcements' => ['add', 'edit', 'delete']]])->assertOk()
            ->assertJsonPath('data.denied_actions.announcements', ['add', 'edit', 'delete']);

        $this->asUser($person)->getJson('/api/v1/admin/announcements')->assertOk();
        $this->asUser($person)->postJson('/api/v1/admin/announcements', $draft)->assertForbidden()->assertJsonPath('code', 'action_denied')->assertJsonPath('action', 'add');
        $this->asUser($person)->putJson("/api/v1/admin/announcements/{$notice->id}", ['title_en' => 'Again'])->assertForbidden()->assertJsonPath('action', 'edit');
        $this->asUser($person)->postJson("/api/v1/admin/announcements/{$notice->id}/pin")->assertForbidden()->assertJsonPath('action', 'edit');
        $this->asUser($person)->deleteJson("/api/v1/admin/announcements/{$notice->id}")->assertForbidden()->assertJsonPath('action', 'delete');
        $this->assertSame('Edited', $notice->fresh()->title_en);
        // The rights themselves are untouched: the page still lists them, and the person is told what was taken.
        $me = $this->asUser($person)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertContains('announcements.manage', $me->json('data.permissions'));
        $this->assertSame(['add', 'edit', 'delete'], $me->json('data.denied_actions.announcements'));
    }

    public function test_each_action_is_taken_by_itself(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        [$person, $grant] = $this->person($admin);
        $notice = $this->announcement($admin);

        // May add and edit, may not delete.
        $this->limit($admin, $person, $grant, ['denied_actions' => ['announcements' => ['delete']]])->assertOk();
        $this->asUser($person)->postJson('/api/v1/admin/announcements', ['title_ar' => 'جديد', 'title_en' => 'New'])->assertCreated();
        $this->asUser($person)->putJson("/api/v1/admin/announcements/{$notice->id}", ['title_en' => 'Edited'])->assertOk();
        $this->asUser($person)->deleteJson("/api/v1/admin/announcements/{$notice->id}")->assertForbidden()->assertJsonPath('action', 'delete');

        // Giving it back restores the person at once.
        $this->limit($admin, $person, $grant, ['denied_actions' => []])->assertOk();
        $this->assertNull($grant->fresh()->denied_actions);
        $this->asUser($person)->deleteJson("/api/v1/admin/announcements/{$notice->id}")->assertSuccessful();
    }

    public function test_the_limit_is_this_person_s_alone_and_only_in_the_area_it_names(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        [$person, $grant] = $this->person($admin);
        [$colleague] = $this->person($admin);
        $draft = ['title_ar' => 'جديد', 'title_en' => 'New'];

        $this->limit($admin, $person, $grant, ['denied_actions' => ['announcements' => ['add']]])->assertOk();

        $this->asUser($person)->postJson('/api/v1/admin/announcements', $draft)->assertForbidden();
        $this->asUser($colleague)->postJson('/api/v1/admin/announcements', $draft)->assertCreated();
        $this->asUser($admin)->postJson('/api/v1/admin/announcements', $draft)->assertCreated();
        // Another area of the same person is not touched: what is refused there is refused for the usual reason.
        $this->assertNotSame('action_denied', $this->asUser($person)->postJson('/api/v1/admin/employees', [])->json('code'));
    }

    public function test_pages_hidden_from_a_person_reach_their_session_and_what_the_administrator_is_shown(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        [$person, $grant] = $this->person($admin);
        [$colleague] = $this->person($admin);

        $this->limit($admin, $person, $grant, ['hidden_pages' => ['/admin/chats', '/portal/library/', '/admin/chats']])->assertOk()
            ->assertJsonPath('data.hidden_pages', ['/admin/chats', '/portal/library']);

        $this->assertSame(['/admin/chats', '/portal/library'], $this->asUser($person)->getJson('/api/v1/auth/me')->assertOk()->json('data.hidden_pages'));
        $this->assertSame([], $this->asUser($colleague)->getJson('/api/v1/auth/me')->assertOk()->json('data.hidden_pages'));
        $role = collect($this->asUser($admin)->getJson("/api/v1/admin/users/{$person->id}/access")->assertOk()->json('data.roles'))->firstWhere('id', $grant->id);
        $this->assertSame(['/admin/chats', '/portal/library'], $role['hidden_pages']);
        // The rights sent with it are kept, and a later change of rights alone keeps the pages.
        $this->asUser($admin)->putJson("/api/v1/admin/users/{$person->id}/roles/{$grant->id}/permissions", ['granted' => ['announcements.manage'], 'revoked' => []])->assertOk();
        $this->assertSame(['/admin/chats', '/portal/library'], $grant->fresh()->hidden_pages);
    }

    public function test_what_is_sent_is_checked_and_written_down(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        [$person, $grant] = $this->person($admin);

        $this->limit($admin, $person, $grant, ['hidden_pages' => ['https://elsewhere.example/admin']])->assertStatus(422);
        $this->limit($admin, $person, $grant, ['hidden_pages' => ['/login']])->assertStatus(422);
        $this->limit($admin, $person, $grant, ['denied_actions' => ['announcements' => ['view']]])->assertStatus(422);
        // An area that has no rights is dropped; the order of the actions is steady.
        $this->limit($admin, $person, $grant, ['denied_actions' => ['nothing' => ['add'], 'announcements' => ['delete', 'add']]])->assertOk()
            ->assertJsonPath('data.denied_actions', ['announcements' => ['add', 'delete']]);
        // The person cannot lift their own limits.
        $this->limit($person, $person, $grant, ['denied_actions' => []])->assertForbidden();

        // Several changes land in the same second, so the entry is found by what it says.
        $log = AuditLog::where('action', 'user_permissions_changed')->where('auditable_id', $person->id)->get()->first(fn (AuditLog $l) => ($l->new_values['denied_actions'] ?? []) !== []);
        $this->assertNotNull($log);
        $this->assertSame(['announcements' => ['add', 'delete']], $log->new_values['denied_actions']);
        $this->assertSame([], $log->old_values['denied_actions']);
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_a_request_is_named_by_what_it_does(): void
    {
        $this->assertNull(WriteAction::named('GET', 'index'));
        $this->assertSame('add', WriteAction::named('POST', 'store'));
        $this->assertSame('add', WriteAction::named('POST', 'importRows'));
        $this->assertSame('edit', WriteAction::named('POST', 'publish'));
        $this->assertSame('edit', WriteAction::named('PUT', 'update'));
        $this->assertSame('edit', WriteAction::named('PATCH', 'store'));
        $this->assertSame('delete', WriteAction::named('DELETE', 'destroy'));
        $this->assertSame('delete', WriteAction::named('POST', 'bulkDelete'));
        $this->assertSame('delete', WriteAction::named('POST', 'removeMedia'));
        // A POST that only reads is not a change.
        $this->assertNull(WriteAction::named('POST', 'preview'));
        $this->assertNull(WriteAction::named('POST', 'exportMinistry'));
        $this->assertSame('saber', WriteAction::area('saber.day.edit'));
    }
}
