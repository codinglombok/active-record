# lombokclarion/active-record

**Opt-in ActiveRecord: Model with CRUD, query builder, eager-loading. Forbidden from `app/Domain/`.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/active-record
```

## Namespace

```php
LombokClarion\ActiveRecord
```

## What's Inside

| Class | Role |
|-------|------|
| `Model` | Base class: CRUD, query builder, `$fillable`, `with()` eager-loading |
| `ModelQueryBuilder` | Fluent query builder wrapping `QueryBuilder` |
| `ActiveRecordException` | Base exception |

## Usage

```php
use LombokClarion\ActiveRecord\Model;
use LombokClarion\Persistence\Relation;

class Widget extends Model {
    protected string $table = 'widgets';
    protected array $fillable = ['name', 'status'];

    public function comments(): Relation {
        return Relation::hasMany('comments', 'widget_id');
    }
}

// CRUD
$widget = Widget::create(['name' => 'Gadget', 'status' => 'active']);
$widget = Widget::find(1);
$widget->update(['status' => 'archived']);
$widget->delete();

// Query
$active = Widget::query()
    ->where('status', '=', 'active')
    ->orderBy('name')
    ->limit(10)
    ->get();

// Eager-loading (N+1 safe)
$widgets = Widget::query()->with('comments')->get();
```

> **Note:** This package carries `forbidden-layers: ["app/Domain"]`. The domain boundary checker blocks imports of `LombokClarion\ActiveRecord\*` from domain code.

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
