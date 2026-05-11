<div id="regeneratethumbnails-batch-tool" class="panel" data-ajax-url="{$ajax_url|escape:'html':'UTF-8'}">
  <h3>Batch thumbnail regeneration</h3>
  <p>Process thumbnails in AJAX batches to avoid server timeouts.</p>

  <div class="form-group">
    <label for="rt-scope">Image scope</label>
    <select id="rt-scope" class="form-control">
      <option value="all">All</option>
      <option value="products">Products</option>
      <option value="categories">Categories</option>
      <option value="manufacturers">Brands</option>
      <option value="suppliers">Suppliers</option>
      <option value="stores">Stores</option>
    </select>
  </div>

  <div class="form-group">
    <label for="rt-type">Image type</label>
    <select id="rt-type" class="form-control">
      <option value="0">All</option>
      {foreach from=$image_types item=image_type}
        <option value="{$image_type.id|intval}">{$image_type.name|escape:'html':'UTF-8'}</option>
      {/foreach}
    </select>
  </div>

  <div class="form-group">
    <label for="rt-batch-size">Batch size per AJAX request</label>
    <input id="rt-batch-size" type="number" min="1" max="500" value="50" class="form-control" />
  </div>

  <div class="form-group">
    <label>
      <input id="rt-erase" type="checkbox" />
      Erase previous generated thumbnails before regeneration
    </label>
  </div>

  <div class="form-group">
    <button id="rt-start" type="button" class="btn btn-primary">
      Start batch regeneration
    </button>
  </div>

  <div class="progress" style="height: 20px; margin-top: 10px;">
    <div id="rt-progress-bar" class="progress-bar" role="progressbar" style="width:0%;">0%</div>
  </div>

  <p id="rt-status" style="margin-top: 10px;"></p>
  <pre id="rt-log" style="max-height: 240px; overflow: auto;"></pre>
</div>

