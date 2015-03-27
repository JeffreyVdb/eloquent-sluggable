<?php

namespace JeffreyVdb\EloquentSluggable;


interface SluggableInterface {

	public function getSlug();

	public function sluggify($force=false);

	public function resluggify();

}
