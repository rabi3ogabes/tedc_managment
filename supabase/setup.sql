-- =============================================================================
-- TEDC Training Management & Impact Platform — Supabase hardening
-- -----------------------------------------------------------------------------
-- Run AFTER the Laravel migrations (`php artisan migrate --force`) against the
-- Supabase Postgres database:
--
--   psql "$SUPABASE_DB_URL" -f supabase/setup.sql
--
-- Architecture:
--   * The Laravel API connects with the database owner role and is the single
--     writer for business data. It enforces RBAC in application code.
--   * Browsers / mobile apps talk to Supabase directly ONLY for:
--       - Supabase Auth (sign-in, token refresh)
--       - Realtime subscriptions to their own notifications
--       - Public assets bucket (program covers, trainer photos, news images)
--   * Row Level Security is enabled on EVERY table so the anon / authenticated
--     roles exposed through PostgREST can never read business data directly,
--     except the narrowly scoped policies below.
-- =============================================================================

begin;

-- -----------------------------------------------------------------------------
-- Helper functions
-- -----------------------------------------------------------------------------

-- Maps the Supabase auth user (auth.uid()) to the platform user id.
create or replace function public.app_user_id()
returns uuid
language sql
stable
security definer
set search_path = public
as $$
  select id from public.users where auth_id = auth.uid() and deleted_at is null limit 1
$$;

-- True when the current auth user holds the given permission (super admins hold all).
create or replace function public.has_permission(p_slug text)
returns boolean
language sql
stable
security definer
set search_path = public
as $$
  select exists (
    select 1
    from public.role_user ru
    join public.roles r on r.id = ru.role_id
    left join public.permission_role pr on pr.role_id = r.id
    left join public.permissions p on p.id = pr.permission_id
    where ru.user_id = public.app_user_id()
      and (r.slug = 'super_admin' or p.slug = p_slug)
  )
$$;

revoke all on function public.app_user_id() from public;
revoke all on function public.has_permission(text) from public;
grant execute on function public.app_user_id() to authenticated;
grant execute on function public.has_permission(text) to authenticated;

-- -----------------------------------------------------------------------------
-- Enable RLS on every application table (default deny for anon/authenticated)
-- -----------------------------------------------------------------------------
do $$
declare
  t record;
begin
  for t in
    select tablename from pg_tables
    where schemaname = 'public'
      and tablename not in ('migrations')
  loop
    execute format('alter table public.%I enable row level security', t.tablename);
    execute format('alter table public.%I force row level security', t.tablename);
    execute format('revoke all on public.%I from anon', t.tablename);
  end loop;
end $$;

-- The API role must bypass RLS. When Laravel connects as "postgres" (owner) this
-- is implicit; if a dedicated role is used, grant it BYPASSRLS:
--   alter role tedc_api bypassrls;
alter table public.migrations enable row level security;

-- -----------------------------------------------------------------------------
-- Narrow read policies for direct client access
-- -----------------------------------------------------------------------------

-- Notifications: each user sees and marks only their own rows (Realtime relies on this).
drop policy if exists "notifications_select_own" on public.notifications;
create policy "notifications_select_own" on public.notifications
  for select to authenticated
  using (user_id = public.app_user_id());

drop policy if exists "notifications_update_own" on public.notifications;
create policy "notifications_update_own" on public.notifications
  for update to authenticated
  using (user_id = public.app_user_id())
  with check (user_id = public.app_user_id());

grant select, update (read_at) on public.notifications to authenticated;

-- Public announcements (news page) are readable by everyone once published.
drop policy if exists "announcements_public_read" on public.announcements;
create policy "announcements_public_read" on public.announcements
  for select to anon, authenticated
  using (is_public and published_at is not null and published_at <= now());
grant select on public.announcements to anon, authenticated;

-- Audit logs are append-only even for the owner role: block UPDATE / DELETE.
create or replace function public.audit_logs_immutable()
returns trigger language plpgsql as $$
begin
  raise exception 'audit_logs is append-only';
end $$;

drop trigger if exists audit_logs_no_update on public.audit_logs;
create trigger audit_logs_no_update
  before update or delete on public.audit_logs
  for each row execute function public.audit_logs_immutable();

-- -----------------------------------------------------------------------------
-- Realtime
-- -----------------------------------------------------------------------------
do $$
begin
  if exists (select 1 from pg_publication where pubname = 'supabase_realtime') then
    begin
      alter publication supabase_realtime add table public.notifications;
    exception when duplicate_object then null;
    end;
  end if;
end $$;

-- -----------------------------------------------------------------------------
-- Storage buckets
--   public-assets : public read (covers, photos, news images, school logos)
--   materials, submissions, certificates, documents : private — accessed only
--   through short-lived signed URLs issued by the API after authorization.
-- -----------------------------------------------------------------------------
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values
  ('public-assets', 'public-assets', true, 5242880, array['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml']),
  ('materials', 'materials', false, 104857600, null),
  ('submissions', 'submissions', false, 52428800, array[
      'application/pdf', 'application/msword',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'image/png', 'image/jpeg', 'image/webp', 'image/heic']),
  ('certificates', 'certificates', false, 10485760, array['application/pdf']),
  ('documents', 'documents', false, 52428800, null)
on conflict (id) do update
  set public = excluded.public,
      file_size_limit = excluded.file_size_limit,
      allowed_mime_types = excluded.allowed_mime_types;

drop policy if exists "public_assets_read" on storage.objects;
create policy "public_assets_read" on storage.objects
  for select to anon, authenticated
  using (bucket_id = 'public-assets');

-- No insert/update/delete policies for anon/authenticated: uploads go through the
-- API using the service role key, which bypasses storage RLS.

commit;
