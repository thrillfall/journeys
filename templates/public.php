<?php
/** @var array $_ */
?>
<div class="jd-public">
	<div class="jd-inner">
	<?php if (!empty($_['cover'])): ?>
		<div class="jd-hero"><img src="<?php p($_['cover']); ?>" alt="" loading="eager"></div>
	<?php endif; ?>
	<header class="jd-pub-header">
		<h1><?php p($_['title']); ?></h1>
		<?php if (!empty($_['description'])): ?>
			<p class="jd-pub-desc"><?php p($_['description']); ?></p>
		<?php endif; ?>

		<?php if (!empty($_['overview'])): ?>
			<div class="jd-overview">
				<?php foreach ($_['overview'] as $c): ?>
					<span class="jd-country">
						<strong><?php p($c['country']); ?></strong>
						<?php if (!empty($c['cities'])): ?>
							<span class="jd-cities">— <?php p(implode(', ', $c['cities'])); ?></span>
						<?php endif; ?>
					</span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</header>

	<?php if (!empty($_['mapUrl'])): ?>
	<div class="jd-map">
		<img class="jd-map-img" src="<?php p($_['mapUrl']); ?>" loading="lazy"
			alt="<?php p($l->t('Map of the journey route')); ?>">
	</div>
	<?php endif; ?>

	<?php if (empty($_['entries'])): ?>
		<p class="jd-empty"><?php p($l->t('This journal has no entries yet.')); ?></p>
	<?php endif; ?>

	<?php if (count($_['entries']) > 1): ?>
		<input type="checkbox" id="jd-order" class="jd-order-cb">
		<div class="jd-sort">
			<label for="jd-order" class="jd-order-label">
				<span class="jd-order-text jd-order-text--asc">↑ <?php p($l->t('Oldest first')); ?></span>
				<span class="jd-order-text jd-order-text--desc">↓ <?php p($l->t('Newest first')); ?></span>
			</label>
		</div>
	<?php endif; ?>

	<?php $lb = 0; $lbTotal = array_sum(array_map(static fn($e) => count($e['photos']), $_['entries'])); ?>
	<ol class="jd-timeline">
		<?php foreach ($_['entries'] as $e): ?>
			<li class="jd-entry">
				<div class="jd-entry-meta">
					<time class="jd-date"><?php p($e['date']); ?></time>
					<?php if (!empty($e['place'])): ?>
						<span class="jd-place">📍 <?php p($e['place']); ?></span>
					<?php endif; ?>
				</div>
				<?php if (!empty($e['title'])): ?>
					<h2 class="jd-entry-title"><?php p($e['title']); ?></h2>
				<?php endif; ?>
				<?php if (!empty($e['body'])): ?>
					<p class="jd-body"><?php print_unescaped(nl2br(htmlspecialchars($e['body'], ENT_QUOTES))); ?></p>
				<?php endif; ?>
				<?php if (!empty($e['photos'])): ?>
					<div class="jd-photos">
						<?php foreach ($e['photos'] as $ph): $lb++; ?>
							<figure class="jd-photo">
								<a href="#lb<?php p($lb); ?>" class="jd-photo__open">
									<img src="<?php p($ph['thumb']); ?>" alt="" loading="lazy">
								</a>
								<?php if (!empty($ph['caption'])): ?>
									<figcaption><?php p($ph['caption']); ?></figcaption>
								<?php endif; ?>
							</figure>
							<div id="lb<?php p($lb); ?>" class="jd-lightbox">
								<a href="#_" class="jd-lightbox__bg"></a>
								<?php if ($lbTotal > 1): $prev = $lb > 1 ? $lb - 1 : $lbTotal; $next = $lb < $lbTotal ? $lb + 1 : 1; ?>
									<a class="jd-lb-nav jd-lb-prev" href="#lb<?php p($prev); ?>" aria-label="Previous">&lsaquo;</a>
									<a class="jd-lb-nav jd-lb-next" href="#lb<?php p($next); ?>" aria-label="Next">&rsaquo;</a>
								<?php endif; ?>
								<a href="#_" class="jd-lightbox__close" aria-label="Close">&times;</a>
								<img src="<?php p($ph['url']); ?>" alt="" loading="lazy">
								<?php if (!empty($ph['caption'])): ?>
									<div class="jd-lb-caption"><?php p($ph['caption']); ?></div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>

	<footer class="jd-pub-footer">
		<?php p($l->t('Shared from a Journeys travel diary')); ?>
	</footer>
	</div>
</div>

<style>
/* Strip Nextcloud branding from this shared page: the logo+name in the header
   and the "Nextcloud – a safe home for all your data" footer. visibility:hidden
   on the appname keeps the 50px header height the lightbox close button relies on. */
#header #nextcloud { visibility: hidden; }
body > footer { display: none; }
/* NC's public #content is fixed-height with overflow clipped, so the page must
   own its scroll. Full-width scroller + centered inner column keeps it centered. */
.jd-public { flex: 1 1 auto; min-width: 0; height: 100%; overflow-y: auto; overflow-x: hidden; box-sizing: border-box; }
.jd-inner { max-width: 820px; margin: 0 auto; padding: 24px 16px 64px; font-family: var(--font-face, sans-serif); color: var(--color-main-text, #222); font-size: 17px; line-height: 1.55; }
.jd-hero { margin: -24px -16px 24px; }
.jd-hero img { width: 100%; max-height: 360px; object-fit: cover; display: block; }
.jd-photo__open { display: block; }
.jd-lightbox { display: none; position: fixed; inset: 0; z-index: 10000; background: rgba(0,0,0,.9);
	align-items: center; justify-content: center; }
.jd-lightbox:target { display: flex; }
.jd-lightbox__bg { position: absolute; inset: 0; }
.jd-lightbox img { max-width: 94vw; max-height: 92vh; object-fit: contain; position: relative; z-index: 1; }
/* The NC header (z-index 2000) sits above #content's stacking context (which is
   fixed → always a stacking context), so it overlaps the lightbox's top strip.
   Keep the close button clear of the 50px header and give it a real hit box. */
.jd-lightbox__close { position: absolute; top: 60px; right: 16px; z-index: 3;
	width: 44px; height: 44px; display: flex; align-items: center; justify-content: center;
	color: #fff; font-size: 34px; line-height: 1; text-decoration: none;
	background: rgba(0, 0, 0, .5); border-radius: 50%; }
/* Prev/next: tall click zones on each side of the image (pure-CSS :target nav). */
.jd-lb-nav { position: absolute; top: 0; bottom: 0; width: 20%; max-width: 140px; z-index: 2;
	display: flex; align-items: center; text-decoration: none; color: #fff;
	font-size: 60px; line-height: 1; opacity: .75; -webkit-user-select: none; user-select: none; }
.jd-lb-nav:hover { opacity: 1; }
.jd-lb-prev { left: 0; justify-content: flex-start; padding-left: 16px; }
.jd-lb-next { right: 0; justify-content: flex-end; padding-right: 16px; }
.jd-lb-caption { position: absolute; bottom: 18px; left: 0; right: 0; z-index: 2; text-align: center;
	color: #fff; font-size: .95em; padding: 0 16px; text-shadow: 0 1px 3px rgba(0,0,0,.8); }
.jd-pub-header { text-align: center; margin-bottom: 32px; }
.jd-pub-header h1 { font-size: 2em; margin: 0 0 8px; }
.jd-pub-desc { color: var(--color-text-maxcontrast, #767676); margin: 0 0 16px; }
.jd-overview { display: flex; flex-wrap: wrap; gap: 8px 16px; justify-content: center; }
/* Static route map: a server-rendered OSM basemap with the route + numbered
   stops baked in, served as a plain <img> (no client JS, no CSP changes). */
.jd-map { margin: 0 0 32px; border-radius: 12px; overflow: hidden; background: var(--color-background-dark, #ededed); }
.jd-map-img { display: block; width: 100%; height: auto; }
.jd-country { background: var(--color-background-dark, #ededed); color: var(--color-main-text, #222); border-radius: 16px; padding: 4px 14px; font-size: .95em; }
.jd-cities { color: var(--color-text-maxcontrast, #767676); }
/* Sort toggle: pure-CSS, no JS (public-page CSP forbids inline script). The
   hidden checkbox reverses the flex column and flips the label text. */
.jd-order-cb { position: absolute; opacity: 0; pointer-events: none; }
.jd-sort { display: flex; justify-content: flex-end; margin: 0 0 16px; }
.jd-order-label { cursor: pointer; -webkit-user-select: none; user-select: none; font-size: .9em;
	padding: 7px 16px; border-radius: 18px; border: 1px solid var(--color-border, #ddd);
	color: var(--color-main-text, #222); background: var(--color-background-hover, #f5f5f5); }
.jd-order-text--desc { display: none; }
.jd-order-cb:checked ~ .jd-sort .jd-order-text--asc { display: none; }
.jd-order-cb:checked ~ .jd-sort .jd-order-text--desc { display: inline; }
.jd-order-cb:focus-visible ~ .jd-sort .jd-order-label { outline: 2px solid var(--color-primary-element, #0082c9); }
.jd-timeline { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; }
.jd-order-cb:checked ~ .jd-timeline { flex-direction: column-reverse; }
.jd-entry { border-left: 3px solid var(--color-primary-element, #0082c9); padding: 0 0 28px 20px; position: relative; }
.jd-entry::before { content: ''; position: absolute; left: -8px; top: 4px; width: 13px; height: 13px; border-radius: 50%; background: var(--color-primary-element, #0082c9); }
.jd-entry-meta { display: flex; gap: 12px; align-items: baseline; flex-wrap: wrap; }
.jd-date { font-weight: 700; }
/* Self-contained neutral pill: don't inherit the theme's low-contrast / accent
   colors here — on some themes the place read as yellow-on-blue and was illegible. */
.jd-place { color: var(--color-main-text, #222); background: var(--color-background-dark, #ededed);
	border-radius: 12px; padding: 2px 10px; font-size: .9em; }
.jd-entry-title { font-size: 1.3em; margin: 4px 0 6px; }
.jd-body { line-height: 1.55; margin: 0 0 12px; white-space: normal; }
.jd-photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; }
.jd-photo { margin: 0; }
.jd-photo img { width: 100%; height: 200px; object-fit: cover; border-radius: 8px; display: block; background: var(--color-background-dark, #e0e0e0); }
.jd-photo figcaption { font-size: .85em; color: var(--color-text-maxcontrast, #767676); margin-top: 4px; }
.jd-empty, .jd-pub-footer { text-align: center; color: var(--color-text-maxcontrast, #888); }
.jd-pub-footer { margin-top: 40px; font-size: .85em; }
@media (max-width: 600px) {
	.jd-inner { padding: 16px 14px 56px; }
	.jd-pub-header { margin-bottom: 24px; }
	.jd-pub-header h1 { font-size: 1.6em; }
	.jd-body { line-height: 1.6; }
	.jd-photos { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
	.jd-photo img { height: 150px; }
	.jd-lb-nav { font-size: 44px; width: 26%; }
}
</style>
