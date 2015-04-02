<?php
namespace JeffreyVdb\EloquentSluggable;

use DevIT\Locales\Locale;
use Illuminate\Support\Str;

trait SluggableTrait
{
    protected $sluggableConfig;

    protected function needsSlugging($key = null)
    {
        $config = $this->getSluggableConfig();
        $save_to = $config['save_to'];
        $on_update = $config['on_update'];

        if (! $key) {
            if (empty($this->{$save_to})) {
                return true;
            }

            if ($this->isDirty($save_to)) {
                return false;
            }
        }
        else {
            if (! isset($this->attributes[$save_to][$key]) ||
                empty($this->attributes[$save_to][$key])
            ) {
                return true;
            }
        }

        return (! $this->exists || $on_update);
    }


    protected function getSlugSource($key = null)
    {
        $config = $this->getSluggableConfig();
        $from = $config['build_from'];

        if (is_null($from)) {
            return $this->__toString();
        }

        if ($key) {
            $func = function ($attribute) use ($key) {
                return $this->attributes[$attribute][$key];
            };
        }
        else {
            $func = function ($attribute) {
                return $this->{$attribute};
            };
        }

        $source = array_map($func, (array) $from);

        return join($source, ' ');
    }


    protected function generateSlug($source)
    {
        $config = $this->getSluggableConfig();
        $separator = $config['separator'];
        $method = $config['method'];
        $max_length = $config['max_length'];

        if ($method === null) {
            $slug = Str::slug($source, $separator);
        }
        elseif (is_callable($method)) {
            $slug = call_user_func($method, $source, $separator);
        }
        else {
            throw new \UnexpectedValueException("Sluggable method is not callable or null.");
        }

        if (is_string($slug) && $max_length) {
            $slug = substr($slug, 0, $max_length);
        }

        return $slug;
    }


    protected function validateSlug($slug)
    {
        $config = $this->getSluggableConfig();
        $reserved = $config['reserved'];

        if ($reserved === null) return $slug;

        // check for reserved names
        if ($reserved instanceof \Closure) {
            $reserved = $reserved($this);
        }

        if (is_array($reserved)) {
            if (in_array($slug, $reserved)) {
                return $slug . $config['separator'] . '1';
            }
            return $slug;
        }

        throw new \UnexpectedValueException("Sluggable reserved is not null, an array, or a closure that returns null/array.");

    }

    protected function makeSlugUnique($slug, $key = null)
    {
        $config = $this->getSluggableConfig();
        if (! $config['unique']) return $slug;

        $separator = $config['separator'];
        $use_cache = $config['use_cache'];

        // if using the cache, check if we have an entry already instead
        // of querying the database
        if ($use_cache) {
            $increment = \Cache::tags('sluggable')->get($slug);
            if ($increment === null) {
                \Cache::tags('sluggable')->put($slug, 0, $use_cache);
            }
            else {
                \Cache::tags('sluggable')->put($slug, ++$increment, $use_cache);
                $slug .= $separator . $increment;
            }
            return $slug;
        }

        // no cache, so we need to check directly
        // find all models where the slug is like the current one
        $list = $this->getExistingSlugs($slug, $key);

        // if ...
        // 	a) the list is empty
        // 	b) our slug isn't in the list
        // 	c) our slug is in the list and it's for our model
        // ... we are okay
        if (
            count($list) === 0 ||
            ! in_array($slug, $list) ||
            (array_key_exists($this->getKey(), $list) && $list[$this->getKey()] === $slug)
        ) {
            return $slug;
        }


        // map our list to keep only the increments
        $len = strlen($slug . $separator);
        array_walk($list, function (&$value, $key) use ($len) {
            $value = intval(substr($value, $len));
        });

        // find the highest increment
        rsort($list);
        $increment = reset($list) + 1;

        return $slug . $separator . $increment;

    }


    protected function getExistingSlugs($slug, $key = null)
    {
        $config = $this->getSluggableConfig();
        $save_to = $config['save_to'];
        $include_trashed = $config['include_trashed'];

        $instance = new static;

        if ($key) {
            $save_to = sprintf("%s->'%s'", $save_to, $key);
        }

        $query = $instance->where(\DB::raw($save_to), 'LIKE', $slug . '%');

        // include trashed models if required
        if ($include_trashed && $this->usesSoftDeleting()) {
            $query = $query->withTrashed();
        }

        // get a list of all matching slugs
        if (isset($config['i18n_slug']) && $config['i18n_slug'] === true) {
            $rows = $query->select(\DB::raw($save_to . ' AS slug, ' . $this->getKeyName()))
                ->get();

            $list = [];
            foreach ($rows as $row) {
                $list[$row[$this->getKeyName()]] = $row->attributes['slug'];
            }
        }
        else {
            $list = $query->lists($save_to, $this->getKeyName());
        }

        return $list;
    }


    protected function usesSoftDeleting()
    {
        return in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses($this));
    }


    protected function setSlug($slug, $key = null)
    {
        $config = $this->getSluggableConfig();
        $save_to = $config['save_to'];
        if ($key) {
            $this->attributes[$save_to][$key] = $slug;
        }
        else {
            $this->setAttribute($save_to, $slug);
        }
    }


    public function getSlug()
    {
        $config = $this->getSluggableConfig();
        $save_to = $config['save_to'];
        return $this->getAttribute($save_to);
    }


    public function sluggify($force = false)
    {
        $config = $this->getSluggableConfig();
        $makeSlugs = function ($key = null) use ($force) {
            if ($force || $this->needsSlugging($key)) {
                $source = $this->getSlugSource($key);
                $slug = $this->generateSlug($source);

                $slug = $this->validateSlug($slug);
                $slug = $this->makeSlugUnique($slug, $key);

                $this->setSlug($slug, $key);
            }
        };

        if ($config['i18n_slug'] === true) {
            // Ensure is an array
            if (! isset($this->attributes[$config['save_to']])) {
                $this->attributes[$config['save_to']] = [];
            }
            elseif (! is_array($arr = $this->attributes[$config['save_to']])) {
                $arr = $this->hstoreToArray($arr);
                $this->attributes[$config['save_to']] = $arr;
            }

            $buildFrom = $this->attributes[$config['build_from']];
            foreach (array_keys($buildFrom) as $key) {
                $makeSlugs($key);
            }
        }
        else {
            $makeSlugs();
        }

        return $this;
    }


    public function resluggify()
    {
        return $this->sluggify(true);
    }


    public static function getBySlug($slug)
    {
        $instance = new static;
        $config = $instance->getSluggableConfig();

        return $instance->where(\DB::raw($config['save_to_dbcol']), $slug)->get();
    }

    public static function findBySlug($slug)
    {
        $instance = new static;
        $config = $instance->getSluggableConfig();

        return $instance->where(\DB::raw($config['save_to_dbcol']), $slug)->first();
    }

    public function getSluggableConfig()
    {
        if ($this->sluggableConfig) {
            return $this->sluggableConfig;
        }

        $defaults = \App::make('config')->get('sluggable');
        if (property_exists($this, 'sluggable')) {
            $this->sluggableConfig = array_merge($defaults, $this->sluggable);
        }
        else {
            $this->sluggableConfig = $defaults;
        }

        if (isset($this->sluggableConfig['i18n_slug']) &&
            $this->sluggableConfig['i18n_slug'] === true
        ) {

            $this->sluggableConfig['save_to_dbcol'] = sprintf("%s->'%s'",
                $this->sluggableConfig['save_to'], \App::getLocale());
        }
        else {
            $this->sluggableConfig['save_to_dbcol'] = $this->sluggableConfig['save_to'];
            $this->sluggableConfig['i18n_slug'] = false;
        }

        return $this->sluggableConfig;
    }
}
