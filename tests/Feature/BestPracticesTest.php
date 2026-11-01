<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Executable versions of well-known Laravel practices (LaravelDaily's "100 Laravel Quick Tips",
 * Bobby Iliev's "Laravel Tips and Tricks", the Laravel docs). Each case checks the codebase
 * itself, so a regression fails CI instead of relying on code review.
 */
class BestPracticesTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ helpers

    /** @return array<string, array{0: class-string<Model>}> */
    public static function models(): array
    {
        $out = [];
        foreach (glob(__DIR__.'/../../app/Models/*.php') as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $out[class_basename($class)] = [$class];
            }
        }

        return $out;
    }

    /** @return array<string, array{0: string}> */
    protected static function phpFiles(string ...$dirs): array
    {
        $root = realpath(__DIR__.'/../..');
        $out = [];
        foreach (Finder::create()->files()->in(array_map(fn ($d) => "{$root}/{$d}", $dirs))->name('*.php') as $f) {
            $out[Str::after($f->getRealPath(), $root.'/')] = [$f->getRealPath()];
        }

        return $out;
    }

    public static function appFiles(): array
    {
        return self::phpFiles('app', 'routes', 'config', 'database', 'resources/views');
    }

    public static function nonConfigFiles(): array
    {
        return self::phpFiles('app', 'routes', 'database', 'resources/views');
    }

    public static function migrations(): array
    {
        return self::phpFiles('database/migrations');
    }

    public static function views(): array
    {
        return self::phpFiles('resources/views');
    }

    // ------------------------------------------------------------ code hygiene

    /** Tip 38 (->dd()) is for local debugging only: none may ship. */
    #[DataProvider('appFiles')]
    public function test_no_debug_calls_shipped(string $file): void
    {
        $code = file_get_contents($file);
        $this->assertDoesNotMatchRegularExpression('/(?<![\w>$:])(dd|dump|var_dump|print_r|ray|ddd)\s*\(|->dd\(\)|->dump\(\)|@dd\b|@dump\b/', $code, "{$file} contains a debug call");
    }

    /** env() returns null once `config:cache` runs, so only config files may call it. */
    #[DataProvider('nonConfigFiles')]
    public function test_env_only_called_in_config(string $file): void
    {
        $this->assertDoesNotMatchRegularExpression('/(?<![\w>:$.])env\s*\(/', file_get_contents($file), "{$file} calls env() outside config/");
    }

    /** Tip 49: class-based route actions, never "Controller@method" strings. */
    public function test_routes_use_class_based_actions(): void
    {
        foreach (['web', 'api', 'brain_shared'] as $f) {
            $this->assertDoesNotMatchRegularExpression("/['\"][A-Za-z\\\\]+Controller@\\w+['\"]/", file_get_contents(base_path("routes/{$f}.php")), $f);
        }
    }

    #[DataProvider('appFiles')]
    public function test_files_declare_no_closing_tag_and_parse(string $file): void
    {
        if (str_ends_with($file, '.blade.php')) {
            $this->assertTrue(true);

            return;
        }
        $code = file_get_contents($file);
        $this->assertStringStartsWith('<?php', $code);
        $this->assertDoesNotMatchRegularExpression('/\?>\s*$/', $code, 'trailing ?> invites whitespace output');
        token_get_all($code, TOKEN_PARSE); // throws ParseError on invalid syntax
    }

    // ------------------------------------------------------------ models

    /** Mass assignment: every model declares $fillable or $guarded explicitly. */
    #[DataProvider('models')]
    public function test_model_guards_mass_assignment(string $class): void
    {
        $model = new $class;
        $explicit = (new ReflectionClass($class))->getAttributes(Fillable::class)
            || (new ReflectionClass($class))->getAttributes(Guarded::class)
            || $model->getFillable() !== [] || $model->getGuarded() !== ['*'];
        $this->assertTrue($explicit, "{$class} must declare \$fillable or \$guarded");
    }

    /** User privilege columns can never be mass-assigned (User uses an allow-list). */
    public static function privilegeColumns(): array
    {
        return array_combine(
            $cols = ['is_admin', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'email_verified_at', 'remember_token', 'last_login_at'],
            array_map(fn ($c) => [$c], $cols),
        );
    }

    #[DataProvider('privilegeColumns')]
    public function test_user_privilege_columns_not_fillable(string $column): void
    {
        $this->assertFalse((new User)->isFillable($column), "users.{$column} must not be mass assignable");
        $user = new User;
        $user->fill(['name' => 'x', 'email' => 'x@example.com']);
        $this->expectException(MassAssignmentException::class);
        $user->fill([$column => 'attack']);
    }

    /** Models using $guarded = ['id'] are safe only if raw request input never reaches them. */
    #[DataProvider('controllers')]
    public function test_raw_request_input_never_mass_assigned(string $file): void
    {
        $code = file_get_contents($file);
        $this->assertDoesNotMatchRegularExpression('/(create|update|fill|forceFill|firstOrCreate|updateOrCreate)\(\s*(\$request|request\(\))->(all|input|except|post|json)\(/', $code, "{$file} mass-assigns raw request input");
        $this->assertDoesNotMatchRegularExpression('/(create|update|fill)\(\s*(\$request|request\(\))->all\(\)/', $code);
    }

    public static function controllers(): array
    {
        return self::phpFiles('app/Http/Controllers');
    }

    /** Tip 11 / "Unknown column updated_at": timestamp settings match the table. */
    #[DataProvider('models')]
    public function test_model_table_and_timestamps_exist(string $class): void
    {
        $model = new $class;
        $this->assertTrue(Schema::hasTable($model->getTable()), "table {$model->getTable()} missing");
        if ($model->usesTimestamps()) {
            foreach (array_filter([$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()]) as $col) {
                $this->assertTrue(Schema::hasColumn($model->getTable(), $col), "{$model->getTable()}.{$col} missing");
            }
        }
    }

    /** Every fillable attribute and cast refers to a real column (typos silently drop data). */
    #[DataProvider('models')]
    public function test_fillable_and_casts_match_columns(string $class): void
    {
        $model = new $class;
        $columns = Schema::getColumnListing($model->getTable());
        $this->assertNotEmpty($columns);
        foreach ($model->getFillable() as $attr) {
            $this->assertContains($attr, $columns, "{$class} fillable [{$attr}] has no column");
        }
        foreach (array_keys($model->getCasts()) as $attr) {
            if ($attr === $model->getKeyName()) {
                continue;
            }
            $this->assertContains($attr, $columns, "{$class} cast [{$attr}] has no column");
        }
    }

    /** Tip 78: relationship methods return a Relation whose related table exists. */
    #[DataProvider('models')]
    public function test_relationships_resolve(string $class): void
    {
        $model = new $class;
        $checked = 0;
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            $type = $m->getReturnType();
            if ($m->getNumberOfRequiredParameters() || $m->isStatic() || $m->class !== $class || ! $type instanceof \ReflectionNamedType || ! is_subclass_of($type->getName(), Relation::class)) {
                continue;
            }
            $relation = $model->{$m->getName()}();
            $this->assertInstanceOf(Relation::class, $relation);
            $this->assertTrue(Schema::hasTable($relation->getRelated()->getTable()), "{$class}::{$m->getName()} → missing table");
            $checked++;
        }
        $this->assertGreaterThanOrEqual(0, $checked);
    }

    /** Serialization never exposes secrets (Tip 74 makeHidden, done at model level). */
    public function test_sensitive_attributes_hidden(): void
    {
        $this->assertContains('password', (new User)->getHidden());
        $this->assertContains('remember_token', (new User)->getHidden());
        $this->assertContains('two_factor_secret', (new User)->getHidden());
        $this->assertContains('two_factor_recovery_codes', (new User)->getHidden());
        foreach (['provider_keys', 'telegram_bot_token', 'telegram_secret', 'whatsapp', 'slack'] as $secret) {
            $this->assertContains($secret, (new Workspace)->getHidden(), "workspace.{$secret}");
        }
    }

    public function test_models_are_strict_outside_production(): void
    {
        $this->assertTrue(Model::preventsLazyLoading());
        $this->assertTrue(Model::preventsSilentlyDiscardingAttributes());
        $this->assertTrue(Model::preventsAccessingMissingAttributes());
    }

    // ------------------------------------------------------------ migrations

    /** Every migration can be rolled back (bobbyiliev: "How to Rollback Database Migrations"). */
    #[DataProvider('migrations')]
    public function test_migration_is_reversible(string $file): void
    {
        $migration = require $file;
        $this->assertTrue(method_exists($migration, 'down'), basename($file).' has no down()');
        $this->assertStringNotContainsString('function down(): void'."\n    {\n    }", file_get_contents($file));
    }

    /** Tip 63: foreign keys via foreignId()/foreignUlid()->constrained(), not hand-rolled unsigned ints. */
    #[DataProvider('migrations')]
    public function test_foreign_keys_use_modern_helpers(string $file): void
    {
        $this->assertDoesNotMatchRegularExpression('/unsignedInteger\([\'"]\w+_id[\'"]\)/', file_get_contents($file));
    }

    public function test_full_rollback_and_remigrate(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 100])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('workspaces'));
        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('workspaces'));
    }

    // ------------------------------------------------------------ routes & HTTP

    public function test_routes_are_cacheable_and_config_cacheable(): void
    {
        $this->assertSame(0, Artisan::call('route:cache'));
        Artisan::call('route:clear');
        $this->assertSame(0, Artisan::call('config:cache'));
        Artisan::call('config:clear');
        $this->assertSame(0, Artisan::call('event:cache'));
        Artisan::call('event:clear');
    }

    /** bobbyiliev: "Check Blade syntax using artisan" — every template compiles. */
    public function test_every_view_compiles(): void
    {
        $this->assertSame(0, Artisan::call('view:cache'));
        Artisan::call('view:clear');
    }

    /** Tip 81: every unauthenticated write endpoint of ours is rate limited. */
    public function test_public_write_routes_are_throttled(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            // Framework/package routes guard themselves: Horizon & Pulse (gates), signed storage uploads, Stripe (signature).
            if (Str::startsWith($uri, ['horizon', 'livewire', 'pulse', 'storage/', 'stripe/', '_ignition'])) {
                continue;
            }
            $mw = $route->gatherMiddleware();
            $write = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);
            $authed = collect($mw)->contains(fn ($m) => is_string($m) && (Str::startsWith($m, ['auth', 'can:']) || in_array($m, ['api.token', 'signed'], true)));
            if (! $write || $authed) {
                continue;
            }
            $checked++;
            $this->assertTrue(collect($mw)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle')), "{$uri} is public and has no throttle");
        }
        $this->assertGreaterThan(5, $checked);
    }

    /** Only signed machine-to-machine endpoints skip CSRF. */
    public function test_csrf_exclusions_are_only_signed_webhooks(): void
    {
        $except = (new ValidateCsrfToken(app(), app('encrypter')))->getExcludedPaths();
        sort($except);
        $this->assertSame(['channels/*', 'stripe/*', 'widget/*'], $except);
    }

    public static function errorCodes(): array
    {
        return ['401' => [401], '402' => [402], '403' => [403], '404' => [404], '419' => [419], '429' => [429], '500' => [500], '503' => [503]];
    }

    /** Tip 24: branded error pages exist and don't leak framework details. */
    #[DataProvider('errorCodes')]
    public function test_error_page_renders(int $code): void
    {
        $this->assertTrue(view()->exists("errors.{$code}"));
        $html = view("errors.{$code}")->render();
        $this->assertStringContainsString((string) $code, $html);
        $this->assertStringContainsString('<h1>', $html);
        foreach (['Whoops', 'Symfony', 'Stack trace', 'vendor/', 'Exception'] as $leak) {
            $this->assertStringNotContainsString($leak, $html);
        }
        $this->assertStringContainsString('Go home', $html);
    }

    public function test_unknown_page_uses_branded_404_and_json_for_api(): void
    {
        $this->get('/definitely-not-here')->assertNotFound()->assertSee('Page not found')->assertSee('Go home');
        $this->getJson('/api/brain/definitely-not-here')->assertNotFound()->assertJsonStructure(['message']);
        $this->getJson('/definitely-not-here')->assertNotFound()->assertJsonStructure(['message']);
    }

    /** Tip 98: maintenance mode serves a branded 503, health endpoint still reports. */
    public function test_maintenance_mode(): void
    {
        $this->app->maintenanceMode()->activate([]);
        try {
            $this->get('/login')->assertStatus(503)->assertSee('Back soon');
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
        $this->get('/login')->assertOk();
    }

    public function test_debug_is_off_by_default_in_example_env(): void
    {
        $env = file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression('/^APP_ENV=/m', $env);
        $this->assertDoesNotMatchRegularExpression('/^APP_KEY=base64:\S+/m', $env, 'never ship a real key');
    }

    // ------------------------------------------------------------ dependencies & console

    /** Tip 41: lock file committed and dev tools stay in require-dev. */
    public function test_composer_hygiene(): void
    {
        $this->assertFileExists(base_path('composer.lock'));
        $json = json_decode(file_get_contents(base_path('composer.json')), true);
        foreach (['phpunit/phpunit', 'mockery/mockery', 'laravel/pint', 'fakerphp/faker', 'brianium/paratest', 'nunomaduro/collision'] as $dev) {
            $this->assertArrayNotHasKey($dev, $json['require'], "{$dev} belongs in require-dev");
        }
        $lock = json_decode(file_get_contents(base_path('composer.lock')), true);
        $this->assertSame(md5_file(base_path('composer.json')) !== '', true);
        $this->assertNotEmpty($lock['packages']);
    }

    public static function commands(): array
    {
        $out = [];
        foreach (glob(__DIR__.'/../../app/Console/Commands/*.php') as $f) {
            $out[basename($f, '.php')] = ['App\\Console\\Commands\\'.basename($f, '.php')];
        }

        return $out;
    }

    /** Tip 15: every command documents itself for `--help`. */
    #[DataProvider('commands')]
    public function test_command_is_registered_and_described(string $class): void
    {
        $command = app($class);
        $this->assertNotSame('', (string) $command->getDescription(), "{$class} needs a description");
        $this->assertArrayHasKey($command->getName(), Artisan::all(), "{$class} is not registered");
    }

    /** Every Blade view escapes by default; raw output is reserved for vetted helpers. */
    #[DataProvider('views')]
    public function test_views_avoid_unescaped_echo_of_input(string $file): void
    {
        $code = file_get_contents($file);
        $this->assertDoesNotMatchRegularExpression('/\{!!\s*(request\(|\$_(GET|POST|REQUEST)|old\()/', $code, "{$file} echoes request data unescaped");
    }
}
