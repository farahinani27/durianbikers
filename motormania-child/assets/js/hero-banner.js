/**
 * Hero banner - scroll-driven 3D helmet.
 *
 * One fixed WebGL layer spans both panels. Scrolling from panel 1 (black) to
 * panel 2 (white) drives a single normalised progress value, which the helmet
 * reads to yaw from a three-quarter view round to dead front, drifting from
 * the left of the frame to the centre.
 *
 * Config comes off the .db-stage element's data-* attributes so the same file
 * serves the WordPress template and the standalone mock.
 */

import * as THREE from 'three';
import { GLTFLoader }      from 'three/addons/loaders/GLTFLoader.js';
import { MeshoptDecoder }  from 'three/addons/libs/meshopt_decoder.module.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';

/**
 * The helmet travels from the headline panel to the spec panel. Both were
 * looked up by index, which only held while those were panels 0 and 1 - drop
 * any section between them and the helmet would settle in the wrong one. Name
 * them instead, so the running order can change freely.
 */
const heroPanels = ( root ) => ( {
	start: root.querySelector( '.db-panel--dark:not(.db-panel--three)' ),
	end:   root.querySelector( '.db-panel--light' ),
} );

const stage = document.querySelector( '.db-stage' );
const hero  = document.querySelector( '.db-hero' );

if ( stage && hero ) {
	init();
}

function init() {

	/*--------------------------------------------------*/
	/*  Tunables                                        */
	/*--------------------------------------------------*/

	// Yaw in degrees. 0 = whatever the model calls forward; MODEL_FRONT_YAW
	// corrects for the export's own orientation so FRONT really is front.
	const MODEL_FRONT_YAW = 180;
	const START_YAW       = 100;    // right-facing three-quarter in panel 1
	// A full revolution on the way down, settling dead front in panel 2.
	// 100 -> -360 is 460 degrees: one complete turn plus the 100 needed
	// to bring the opening three-quarter round to front. -360 lands on
	// the same facing as 0, so it finishes square to camera.
	const END_YAW         = -360;

	// Framing at each end of the scroll, as fractions of the visible frame.
	// The helmet is fitted to whichever of heightFrac/widthFrac binds first, so
	// a narrow viewport shrinks it instead of letting it blow out sideways.
	const FRAMING = {
		start: { heightFrac: 0.95, widthFrac: 0.74, x: -0.295, y: -0.165 },
		end:   { heightFrac: 0.58, widthFrac: 0.46, x:  0.00, y: -0.045 },
	};

	// Narrow screens: the helmet sits in the band the CSS leaves between the
	// headline and the meta strip. measureBand() overwrites start.heightFrac
	// and start.y from the real layout, so it fits whatever the device gives us.
	const FRAMING_NARROW = {
		start: { heightFrac: 0.42, widthFrac: 0.88, x: 0.00, y: 0.12 },
		end:   { heightFrac: 0.34, widthFrac: 0.86, x: 0.00, y: 0.02 },
	};

	// The mesh's visible mass sits below the centre of its bounding box - the
	// chin bar and strap geometry hang down, and the crown does not balance
	// them. Positioning by the bbox centre therefore renders the helmet low.
	// Measured off a transparent render of the front pose: 7.76% of the
	// model's own height, then trimmed to 0.064 against a measured render so
	// the silhouette lands dead centre. Applied on the settle so panel 2 reads centred;
	// faded out towards panel 1, whose framing is a deliberate crop.
	const CENTROID_DROP = 0.064;

	// Measured off the opening pose: how much of the normalised model height the
	// silhouette actually occupies, and its width/height ratio. Used to turn an
	// available width into a height fraction.
	const POSE_VIS = 0.945;
	const POSE_ASP = 0.9552;
	const CROP_LEFT = 0.13;         // share of the helmet allowed off the left edge
	const DESK_SCALE = 0.95;        // overall size trim on desktop

	const DAMPING = 0.085;          // lower = smoother / laggier
	const NARROW_BREAKPOINT = 767;   // two-column layout runs down to 768

	// How small the intro video ends up, as a share of the viewport.
	const INTRO_END_SCALE = 0.34;

	// Category art dealt along the pointer's path. All transparent PNGs, so
	// they sit on the black without any backing to remove.
	const TRAIL_IMAGES = [
		'https://durianbikers.com/wp-content/uploads/2021/02/flip-up-modular-icon-300x300.png',
		'https://durianbikers.com/wp-content/uploads/2021/02/visors-1-300x300.png',
		'https://durianbikers.com/wp-content/uploads/2021/02/motorcycle-tires-300x300.png',
		'https://durianbikers.com/wp-content/uploads/2021/02/exhaust-icon-300x300.png',
		'https://durianbikers.com/wp-content/uploads/2021/02/lubricant-300x300.png',
	];

	const reduceMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/*--------------------------------------------------*/
	/*  Renderer                                        */
	/*--------------------------------------------------*/

	let renderer;

	try {
		renderer = new THREE.WebGLRenderer( {
			alpha: true,
			antialias: true,
			powerPreference: 'high-performance',
		} );
	} catch ( err ) {
		// No WebGL - the poster stays put and the page still reads fine.
		console.warn( '[hero] WebGL unavailable, keeping poster fallback.', err );
		return;
	}

	renderer.setClearAlpha( 0 );
	renderer.toneMapping = THREE.ACESFilmicToneMapping;
	renderer.toneMappingExposure = 1.05;
	stage.appendChild( renderer.domElement );

	const scene  = new THREE.Scene();
	const camera = new THREE.PerspectiveCamera( 34, 1, 0.1, 100 );
	camera.position.set( 0, 0, 6 );

	// Studio environment - gives the clearcoat something to reflect, and reads
	// correctly against both the black and the white panel.
	const pmrem = new THREE.PMREMGenerator( renderer );
	scene.environment = pmrem.fromScene( new RoomEnvironment(), 0.04 ).texture;

	const key = new THREE.DirectionalLight( 0xffffff, 2.1 );
	key.position.set( 2.5, 3, 4 );
	scene.add( key );

	const rim = new THREE.DirectionalLight( 0xffffff, 1.4 );
	rim.position.set( -3, 1.2, -2.5 );
	scene.add( rim );

	const fill = new THREE.HemisphereLight( 0xffffff, 0x202020, 0.6 );
	scene.add( fill );

	/*--------------------------------------------------*/
	/*  Model                                           */
	/*--------------------------------------------------*/

	const pivot = new THREE.Group();   // yaw happens here, about the true centre
	const rig   = new THREE.Group();   // position + scale happen here
	rig.add( pivot );
	scene.add( rig );

	let modelHeight = 1;
	let modelWidth  = 1;
	let ready = false;

	const loader = new GLTFLoader();
	loader.setMeshoptDecoder( MeshoptDecoder );

	loader.load(
		stage.dataset.model,
		( gltf ) => {
			const model = gltf.scene;

			// Two passes. Object.position lives in PARENT space and is not
			// affected by the object's own scale, so centring before scaling
			// leaves the offset in raw model units and the pivot ends up well
			// off centre. Normalise the scale first, then centre what that
			// produced, so yaw spins about the true middle of the helmet.
			const box  = new THREE.Box3().setFromObject( model );
			const size = box.getSize( new THREE.Vector3() );

			model.scale.setScalar( 1 / size.y );
			model.updateMatrixWorld( true );

			const scaled = new THREE.Box3().setFromObject( model );
			model.position.sub( scaled.getCenter( new THREE.Vector3() ) );

			modelHeight = 1;
			modelWidth  = size.x / size.y;

			model.traverse( ( o ) => {
				if ( ! o.isMesh ) return;
				o.frustumCulled = false;
				const mats = Array.isArray( o.material ) ? o.material : [ o.material ];
				mats.forEach( ( m ) => {
					if ( m && m.map ) m.map.anisotropy = Math.min( 8, renderer.capabilities.getMaxAnisotropy() );
				} );
			} );

			pivot.add( model );
			ready = true;
			stage.classList.add( 'is-ready' );

			// Arm the panel-2 callouts only once the helmet is actually on
			// screen. Until then they stay plainly visible, so a WebGL or model
			// failure can never leave the copy hidden behind an animation that
			// will not run.
			hero.classList.add( 'db-notes-armed' );

			layout();
			initNotesPin();
		},
		undefined,
		( err ) => {
			console.error( '[hero] helmet failed to load:', err );
		}
	);

	/*--------------------------------------------------*/
	/*  Sizing                                          */
	/*--------------------------------------------------*/

	let vw = 0, vh = 0, narrow = false;
	let visibleH = 1, visibleW = 1;

	function layout() {
		vw = stage.clientWidth;
		vh = stage.clientHeight;
		narrow = vw <= NARROW_BREAKPOINT;

		const dprCap = narrow ? 1.5 : 2;
		renderer.setPixelRatio( Math.min( window.devicePixelRatio || 1, dprCap ) );
		renderer.setSize( vw, vh, false );

		camera.aspect = vw / vh;
		camera.updateProjectionMatrix();

		// What the camera actually sees at the model's depth.
		visibleH = 2 * Math.tan( ( camera.fov * Math.PI / 180 ) / 2 ) * camera.position.z;
		visibleW = visibleH * camera.aspect;

		measureScroll();
		if ( narrow ) measureBand();
		else measureDeskFrame();
		measureEndBand();
		apply( eased, true );
	}

	/**
	 * The helmet is centred in whatever gap the CSS leaves between the headline
	 * and the meta strip. Measuring it rather than hard-coding a size keeps it
	 * as large as the layout allows while never landing on the copy, at any
	 * viewport or font rendering.
	 */
	function measureBand() {
		// The band now sits between the meta strip and the copy: the meta strip
		// reads directly under the headline, and the helmet fills the space below it.
		const meta = hero.querySelector( '.db-hero__meta' );
		const copy = hero.querySelector( '.db-hero__copy' );
		if ( ! meta || ! copy ) return;

		const top    = meta.getBoundingClientRect().bottom + window.scrollY;
		const bottom = copy.getBoundingClientRect().top + window.scrollY;
		const height = bottom - top;

		// Anything above a sliver is usable. The old floor of 80px bailed out and
		// left the 0.42 default in place, which rendered a helmet several times
		// the size of the gap and swamped the headline and copy on short phones.
		if ( height < 24 ) return;

		const centre = ( top + bottom ) / 2 - scrollStart;

		FRAMING_NARROW.start.heightFrac =
			Math.max( 0.04, Math.min( 0.68, ( height * 0.92 ) / vh ) );
		FRAMING_NARROW.start.y = ( vh / 2 - ( centre + height * 0.015 ) ) / vh;
	}

	/*--------------------------------------------------*/
	/*  Scroll                                          */
	/*--------------------------------------------------*/

	let scrollStart = 0, scrollEnd = 1;

	/**
	 * ScrollTrigger pins panel 2 by setting position:fixed on it and leaving a
	 * .pin-spacer of the same height behind. A fixed element's rect no longer
	 * describes where it sits in the document, so measuring it directly would
	 * make scrollEnd chase the scroll position and the helmet would never
	 * settle. The spacer is the element that still holds the layout.
	 */
	function layoutBox( el ) {
		const parent = el.parentElement;
		return parent && parent.classList.contains( 'pin-spacer' ) ? parent : el;
	}

	function measureScroll() {
		const { start, end } = heroPanels( hero );
		if ( ! start || ! end ) return;

		const docTop = ( el ) => el.getBoundingClientRect().top + window.scrollY;

		scrollStart = docTop( layoutBox( start ) );
		scrollEnd   = docTop( layoutBox( end ) );

		if ( scrollEnd - scrollStart < 1 ) scrollEnd = scrollStart + 1;
	}

	/**
	 * Centre the settled helmet in the band panel 2 reserves for it
	 * (.db-spec__stage), so it sits between the title and the spec row at
	 * every viewport instead of at a hard-coded offset.
	 */
	function measureEndBand() {
		const { end: endPanel } = heroPanels( hero );
		const stageEl = hero.querySelector( '.db-spec__stage' );
		if ( ! endPanel || ! stageEl ) return;

		const panelTop = endPanel.getBoundingClientRect().top + window.scrollY;
		const rect     = stageEl.getBoundingClientRect();
		const top      = rect.top + window.scrollY - panelTop;
		const height   = rect.height;

		if ( height < 80 ) return;

		const f   = narrow ? FRAMING_NARROW : FRAMING;
		const cap = narrow ? 0.52 : 0.62;

		f.end.heightFrac = Math.min( cap, ( height * 0.94 ) / vh );
		f.end.y = ( vh / 2 - ( top + height / 2 ) ) / vh;

		publishStageMetrics( endPanel, stageEl, f.end );
	}

	/**
	 * Hand the panel-2 callouts the geometry they need: where the reserved
	 * stage sits inside the panel, and how wide the settled helmet actually
	 * renders. Facing front, the model's projected width is its own bounding
	 * box, so this is exact rather than an estimate - the leader lines stop at
	 * the silhouette at any viewport.
	 */
	function publishStageMetrics( panel, stageEl, end ) {
		const inner = panel.querySelector( '.db-panel__inner' );
		if ( ! inner || ! modelWidth || ! modelHeight ) return;

		const innerRect = inner.getBoundingClientRect();
		const stageRect = stageEl.getBoundingClientRect();

		const scale = Math.min(
			( visibleH * end.heightFrac ) / modelHeight,
			( visibleW * end.widthFrac )  / modelWidth
		);
		const halfPx = ( ( modelWidth * scale ) / visibleW ) * vw / 2;

		inner.style.setProperty( '--db-stage-top', ( stageRect.top - innerRect.top ) + 'px' );
		inner.style.setProperty( '--db-stage-h', stageRect.height + 'px' );
		inner.style.setProperty( '--db-helm-half', Math.round( halfPx ) + 'px' );
	}

	/**
	 * Live viewport-centre offset of the band panel 2 reserves for the helmet,
	 * as a fraction of viewport height. Recomputed per frame so the settled
	 * helmet tracks the section rather than the screen.
	 */
	function endYFrac() {
		const el = hero.querySelector( '.db-spec__stage' );
		if ( ! el ) return ( narrow ? FRAMING_NARROW : FRAMING ).end.y;

		const r = el.getBoundingClientRect();
		if ( ! r.height ) return ( narrow ? FRAMING_NARROW : FRAMING ).end.y;

		return ( vh / 2 - ( r.top + r.height / 2 ) ) / vh;
	}

	/**
	 * Size and place the helmet from the space actually left beside the copy.
	 *
	 * A fixed heightFrac meant the height constraint won at every desktop width,
	 * so the helmet stayed the same size from 1440px all the way down to ~1025px
	 * and then jumped when the narrow layout took over. Deriving it from the
	 * content's left edge makes it shrink continuously and guarantees it never
	 * reaches the text or the card rail.
	 */
	function measureDeskFrame() {
		const els = [ '.db-hero__meta', '.db-hero__copy', '.db-cards' ]
			.map( ( sel ) => hero.querySelector( sel ) )
			.filter( Boolean );

		if ( ! els.length || ! vw || ! vh ) return;

		let boundary = vw;
		els.forEach( ( el ) => {
			boundary = Math.min( boundary, el.getBoundingClientRect().left );
		} );

		const gap   = Math.max( 18, vw * 0.022 );
		const avail = Math.max( 140, boundary - gap );

		// Widest the helmet may be drawn, allowing CROP_LEFT to bleed off-screen.
		const drawW = avail / ( 1 - CROP_LEFT );
		const fromW = ( drawW / POSE_ASP ) / POSE_VIS;

		const hf = Math.min( 0.95, fromW / vh ) * DESK_SCALE;

		FRAMING.start.heightFrac = hf;
		FRAMING.start.widthFrac  = 4;      // width is handled here; never binds

		// Right edge sits on the boundary, so the helmet can never touch the copy.
		// Clamped so the left edge never becomes positive: on very wide screens a
		// trimmed helmet would otherwise pull in from the edge and lose the
		// cropped-off-frame look.
		const w     = hf * vh * POSE_VIS * POSE_ASP;
		const right = ( boundary - gap ) * DESK_SCALE;
		const cx    = Math.min( right - w / 2, w / 2 );
		FRAMING.start.x = ( cx - vw / 2 ) / vw;
	}





	/**
	 * The statement fades up as it comes into view, holds while it owns the
	 * screen, and fades back out as it leaves.
	 *
	 * Tied to scroll position rather than fired on enter, so it tracks the
	 * reader both ways - scrubbing back up rewinds it instead of leaving the
	 * text stranded at full opacity.
	 *
	 * The line wipe owns the entrance: it holds every line colour-transparent
	 * until its bar sweeps past, so a fade-in underneath it is invisible no
	 * matter how long it runs. This therefore lifts opacity early - before the
	 * wipe starts, while there is nothing to see anyway - and does its real
	 * work on the way out, where nothing else is competing.
	 */
	function buildStatementFade( gsap ) {
		const section = hero.querySelector( '.db-panel--three' );
		const title = section && section.querySelector( '.db-three__title' );
		const cta = section && section.querySelector( '.db-three__cta' );
		if ( ! title ) return;

		const tl = gsap.timeline( {
			defaults: { ease: 'none' },
			scrollTrigger: {
				trigger: section,
				start: 'top bottom',   // section's top edge meets the fold
				end: 'bottom top',     // ...through to its bottom edge leaving
				scrub: 0.7,            // a little lag, so it glides
				invalidateOnRefresh: true,
			},
		} )
			.fromTo( title, { opacity: 0 }, { opacity: 1, duration: 0.12 }, 0 )
			// Clear by the time only ~20% of the section is left on screen, and
			// start early enough that the words are still in view while it
			// happens - fading them out after they have already scrolled off
			// the top is a change nobody sees.
			.to( title, { opacity: 0, duration: 0.28 }, 0.62 );

		if ( cta ) {
			// Same envelope, but the exit is pulled forward. A saturated green
			// pill still reads as present at an opacity where cream text has
			// already gone, so matching the numbers exactly leaves the button
			// looking like it lingers after the words have cleared.
			tl.fromTo( cta, { opacity: 0 }, { opacity: 1, duration: 0.12 }, 0 )
				.to( cta, { opacity: 0, duration: 0.20 }, 0.58 );
		}
	}

	/**
	 * Deal images along the pointer's path across the first statement, then let
	 * them fade back out - a scattered trail rather than one cursor-following
	 * image.
	 *
	 * Placement is gated on distance travelled, not on every pointermove event:
	 * moving 4px should not spawn anything, and a fast sweep should leave an
	 * evenly spaced trail rather than a clump. The pool is fixed and recycled,
	 * so a long sweep never accumulates nodes.
	 */
	function buildStatementTrail( gsap ) {
		const section = hero.querySelector( '.db-panel--three' );
		if ( ! section || reduceMotion ) return;

		// Nothing to follow on a touch screen - do not even load the images.
		if ( ! window.matchMedia( '(hover: hover) and (pointer: fine)' ).matches ) return;

		const layer = document.createElement( 'div' );
		layer.className = 'db-trail';
		layer.setAttribute( 'aria-hidden', 'true' );
		section.insertBefore( layer, section.firstChild );

		const pool = TRAIL_IMAGES.map( ( src ) => {
			const img = document.createElement( 'img' );
			img.className = 'db-trail__img';
			img.decoding = 'async';
			img.alt = '';
			img.src = src;
			layer.appendChild( img );
			return img;
		} );

		const GAP = 150;   // px of travel between drops
		let last = null;
		let next = 0;

		section.addEventListener( 'pointermove', ( e ) => {
			const r = layer.getBoundingClientRect();
			const x = e.clientX - r.left;
			const y = e.clientY - r.top;

			if ( last && Math.hypot( x - last.x, y - last.y ) < GAP ) return;
			last = { x, y };

			const img = pool[ next++ % pool.length ];
			const tilt = Math.random() * 20 - 10;

			gsap.killTweensOf( img );
			gsap.set( img, {
				x, y,
				xPercent: -50,
				yPercent: -50,
				rotation: tilt,
				scale: 0.82,
				opacity: 0,
			} );

			gsap.timeline()
				.to( img, { opacity: 1, scale: 1, duration: 0.32, ease: 'power3.out' } )
				.to( img, { opacity: 0, scale: 0.94, duration: 0.5, ease: 'power2.in' }, '+=0.25' );
		}, { passive: true } );

		section.addEventListener( 'pointerleave', () => { last = null; }, { passive: true } );
	}

	/**
	 * Each statement line is wiped in behind an accent bar: the bar sweeps in
	 * from the left over the hidden line, the text is switched on underneath
	 * it, then the bar clears off to the right. Same treatment as the hero's
	 * description, driven per section as it comes into view.
	 *
	 * Needs SplitText to know where the lines actually break; without it the
	 * statement simply stays visible, which is the right failure.
	 */
	function buildStatementReveal( gsap, ScrollTrigger ) {
		const titles = hero.querySelectorAll( '.db-three__title' );
		if ( ! titles.length || ! window.SplitText ) return;

		const STEP = 0.13;   // gap between lines
		const IN   = 0.42;   // bar sweeping across
		const OUT  = 0.50;   // bar clearing off

		titles.forEach( ( title ) => {
			let split;
			try {
				split = new window.SplitText( title, { type: 'lines', linesClass: 'db-ln' } );
			} catch ( e ) {
				return;
			}
			if ( ! split.lines.length ) return;

			const tl = gsap.timeline( { paused: true } );

			split.lines.forEach( ( line, i ) => {
				line.classList.add( 'db-ln-masked' );

				const bar = document.createElement( 'span' );
				bar.className = 'db-ln-bar';
				line.appendChild( bar );

				const at = i * STEP;
				tl.to( bar, { scaleX: 1, duration: IN, ease: 'power2.inOut' }, at );
				tl.add( () => line.classList.remove( 'db-ln-masked' ), at + IN );
				tl.set( bar, { transformOrigin: 'right center' }, at + IN );
				tl.to( bar, { scaleX: 0, duration: OUT, ease: 'power2.inOut' }, at + IN );
			} );

			ScrollTrigger.create( {
				trigger: title,
				start: 'top 78%',
				once: true,
				onEnter: () => tl.play(),
			} );
		} );
	}

	/**
	 * Pin panel 2 and play the four callouts across the pinned scroll, so the
	 * sequence is finished before the section can leave the screen. On a plain
	 * timed reveal a quick scroll carried the reader past points that had not
	 * appeared yet.
	 *
	 * Scrubbed rather than fired on enter: tying it to scroll distance means it
	 * cannot outlast the pin however fast or slow the reader moves, and it
	 * rewinds cleanly on the way back up.
	 *
	 * Silently does nothing without GSAP - the CSS keeps the timed sequence as
	 * a fallback, so the points still appear either way.
	 */
	function initNotesPin() {
		const { end: notesPanel } = heroPanels( hero );
		const notes  = hero.querySelectorAll( '.db-note' );
		if ( ! notesPanel || ! notes.length ) return;

		waitForGsap( ( gsap, ScrollTrigger ) => {
			gsap.registerPlugin( ScrollTrigger );

			// Every width, phones included: the sequence should finish before
			// the section can leave the screen regardless of the layout the
			// callouts happen to be in.
			hero.classList.add( 'db-notes-scrub' );

			// The intro's pin inserts scroll space ABOVE panel 2. Created after
			// panel 2's trigger, that space shifts the panel without shifting
			// the trigger, and a refresh does not reconcile it - panel 2 then
			// pins 1440px early and lands on top of panel 1. Build it first.
			buildIntroZoom( gsap );
			buildNotesTimeline( gsap, notesPanel, notes );
			buildStatementReveal( gsap, ScrollTrigger );
			buildStatementTrail( gsap );
			buildStatementFade( gsap );

			ScrollTrigger.refresh();
			layout();
		} );
	}


	/**
	 * The intro video starts filling the viewport and is scaled down to a
	 * framed card as the section is scrolled through, then hands over to
	 * panel 1.
	 *
	 * Scaled, not resized: a transform keeps the whole picture shrinking as one
	 * piece on the compositor. Resizing the frame would re-crop the video at
	 * every step (object-fit recalculating against a new box) and thrash layout.
	 *
	 * The end scale is expressed against the viewport so the framed card keeps
	 * its proportions on any screen.
	 */
	function buildIntroZoom( gsap ) {
		const intro = document.querySelector( '.db-intro' );
		const frame = intro && intro.querySelector( '.db-intro__frame' );
		const video = frame && frame.querySelector( '.db-intro__video' );
		if ( ! intro || ! frame ) return;

		const behind = intro.querySelectorAll( '.db-intro__marquee' );

		// Built up front, before the video resolves, so the document's scroll
		// map is settled before panel 2's trigger is measured against it.
		const tween = gsap.timeline( {
			defaults: { ease: 'none' },
			scrollTrigger: {
					trigger: intro,
					start: 'top top',
					end: () => '+=' + Math.round( window.innerHeight * 1.6 ),
					pin: true,
					pinSpacing: true,
					anticipatePin: 1,
					scrub: 0.5,
					invalidateOnRefresh: true,
					onRefresh: ( self ) => {
						guardPinSpacing( self, intro );
						measureScroll();
					},
			},
		} );

		tween.fromTo( frame, { scale: 1 }, { scale: INTRO_END_SCALE, duration: 1 }, 0 );

		// The video takes on a light dark wash as it shrinks - just enough to
		// settle it back behind the words, not enough to hide the footage.
		//
		// One driver, on the pin's own timeline. A second ScrollTrigger aimed at
		// a pinned element resolves its start AFTER the pin, so spanning both
		// phases that way left the veil flat through the entire zoom; and while
		// the page sits before such a trigger, ScrollTrigger holds it at that
		// tween's start value, which darkened the video before a pixel was
		// scrolled. Keeping it on one timeline avoids both.
		const veil = frame.querySelector( '.db-intro__veil' );
		if ( veil ) {
			tween.fromTo( veil, { opacity: 0 }, { opacity: 0.5, duration: 1 }, 0 );
		}

		// The words only make sense once the video has shrunk off them, so they
		// arrive over the middle third of the zoom rather than at the start.
		if ( behind.length ) {
			tween.fromTo( behind, { opacity: 0 }, { opacity: 1, duration: 0.4 }, 0.3 );
		}

		startIntroVideo( intro, video, ( ok ) => {
			if ( ok ) return;

			// Unusable video: take the section and its scroll back out, then
			// let everything below re-measure against the shorter document.
			intro.classList.add( 'is-failed' );
			tween.scrollTrigger && tween.scrollTrigger.kill( true );
			tween.kill();
			window.ScrollTrigger && window.ScrollTrigger.refresh();
			layout();
		} );
	}

	/**
	 * Decide whether the video is actually usable, then report back once.
	 *
	 * canPlayType is not the test: Chrome returns "" for video/quicktime and
	 * then plays the file anyway by sniffing the codec. Only loading it settles
	 * it, so this waits for real data (or a real error) rather than asking.
	 *
	 * Autoplay being blocked is not a failure - the first frame still renders,
	 * so the zoom is worth running on a still.
	 */
	function startIntroVideo( intro, video, done ) {
		if ( ! video || ! video.dataset.src ) {
			done( false );
			return;
		}

		let settled = false;
		const finish = ( ok ) => {
			if ( settled ) return;
			settled = true;
			if ( ok ) intro.classList.add( 'is-ready' );
			done( ok );
		};

		video.muted = true;              // required for autoplay everywhere
		video.playsInline = true;
		video.addEventListener( 'playing', () => finish( true ), { once: true } );
		video.addEventListener( 'loadeddata', () => finish( video.readyState >= 2 ), { once: true } );
		video.addEventListener( 'error', () => finish( false ), { once: true } );

		video.src = video.dataset.src;

		const attempt = video.play();
		if ( attempt && typeof attempt.catch === 'function' ) {
			// Blocked, not broken - the still is enough to zoom.
			attempt.catch( () => finish( video.readyState >= 2 ) );
		}

		// A stalled network should not hold the section open indefinitely.
		setTimeout( () => finish( video.readyState >= 2 ), 12000 );
	}

	/**
	 * The theme ships a blanket mobile guard:
	 *
	 *   @media (max-width:1024px){ .pin-spacer{ padding:0!important; display:block!important } }
	 *
	 * which zeroes the spacing ScrollTrigger relies on, so at 1024 the pin
	 * engaged but the document never grew - the section stuck and the callouts
	 * froze part-way. Re-assert the spacing as an inline !important (the only
	 * thing that outranks an author !important) on every refresh, since
	 * ScrollTrigger rewrites these values itself. Scoped to our own spacer, so
	 * the theme's rule still governs anything else it pins.
	 */
	function guardPinSpacing( self, panel ) {
		const spacer = panel.parentElement;
		if ( ! spacer || ! spacer.classList.contains( 'pin-spacer' ) ) return;

		const pad = Math.max( 0, Math.round( self.end - self.start ) );
		const panelH = Math.round( panel.getBoundingClientRect().height );

		// height matters as much as the padding. Pinning takes the panel out of
		// flow, so with the theme's `height:auto!important` the spacer collapses
		// to just its padding the moment the pin engages - the document loses
		// the panel's full height and everything below slides up behind the
		// pinned section. ScrollTrigger sets an explicit height for exactly this
		// reason; restore it at a weight the theme rule cannot beat.
		// border-box, so this figure covers the padding too.
		spacer.style.setProperty( 'height', ( panelH + pad ) + 'px', 'important' );
		spacer.style.setProperty( 'min-height', ( panelH + pad ) + 'px', 'important' );
		spacer.style.setProperty( 'padding-bottom', pad + 'px', 'important' );
		spacer.style.setProperty( 'display', 'flex', 'important' );
	}

	function buildNotesTimeline( gsap, notesPanel, notes ) {
		{
			const STEP = 0.8;   // gap between points, in timeline seconds
			const tl = gsap.timeline( {
				scrollTrigger: {
					trigger: notesPanel,
					start: 'top top',
					end: () => '+=' + Math.round( window.innerHeight * 1.15 ),
					pin: true,
					pinSpacing: true,
					anticipatePin: 1,
					scrub: 0.5,
					invalidateOnRefresh: true,
					// Panel 2's document position changes the moment the spacer
					// appears; re-read it so the helmet still settles on cue.
					onRefresh: ( self ) => {
						guardPinSpacing( self, notesPanel );
						measureScroll();
					},
				},
			} );

			notes.forEach( ( note, i ) => {
				const line = note.querySelector( '.db-note__line' );
				tl.fromTo( note,
					{ opacity: 0, y: 8 },
					{ opacity: 1, y: 0, duration: 0.45, ease: 'power2.out' },
					i * STEP );

				if ( line ) {
					tl.fromTo( line,
						{ scaleX: 0 },
						{ scaleX: 1, duration: 0.5, ease: 'power2.out' },
						i * STEP + 0.2 );
				}

				// The dot lands as the leader reaches the helmet.
				const dot = note.querySelector( '.db-note__dot' );
				if ( dot ) {
					tl.fromTo( dot,
						{ scale: 0 },
						{ scale: 1, duration: 0.28, ease: 'back.out(2)' },
						i * STEP + 0.62 );
				}
			} );

			return tl;
		}
	}

	/**
	 * The theme loads GSAP in the footer, which can land after this module.
	 * Poll briefly, then give up and leave the CSS fallback running.
	 */
	function waitForGsap( fn ) {
		let tries = 0;
		( function poll() {
			if ( window.gsap && window.ScrollTrigger ) {
				fn( window.gsap, window.ScrollTrigger );
				return;
			}
			if ( tries++ > 160 ) return;
			requestAnimationFrame( poll );
		} )();
	}

	function readProgress() {
		const raw = ( window.scrollY - scrollStart ) / ( scrollEnd - scrollStart );
		return Math.min( 1, Math.max( 0, raw ) );
	}

	/*--------------------------------------------------*/
	/*  Frame loop                                      */
	/*--------------------------------------------------*/

	const lerp     = ( a, b, t ) => a + ( b - a ) * t;
	const DEG      = Math.PI / 180;
	// Two curves. The framing move stays cinematic (slow out, slow in), but a
	// 460-degree spin on that same curve dumps most of the rotation into the
	// middle of the scroll and reads as a lurch. The spin therefore runs on a
	// much flatter curve - mostly linear, with just enough ease at each end to
	// avoid starting and stopping dead.
	const easeMove = ( t ) => ( t < 0.5 ? 4 * t * t * t : 1 - Math.pow( -2 * t + 2, 3 ) / 2 );
	const smoothstep = ( t ) => t * t * ( 3 - 2 * t );
	const easeSpin = ( t ) => 0.35 * t + 0.65 * smoothstep( t );

	let target = 0;
	let eased  = 0;

	let wasSettled = false;

	function apply( p, snap ) {
		if ( ! ready ) return;

		const e = easeMove( p );
		const r = easeSpin( p );
		const f = narrow ? FRAMING_NARROW : FRAMING;

		const heightFrac = lerp( f.start.heightFrac, f.end.heightFrac, e );
		const widthFrac  = lerp( f.start.widthFrac,  f.end.widthFrac,  e );

		const scale = Math.min(
			( visibleH * heightFrac ) / modelHeight,
			( visibleW * widthFrac )  / modelWidth
		);

		rig.scale.setScalar( scale );
		rig.position.x = lerp( f.start.x, f.end.x, e ) * visibleW;
		// Panel 1 is not always flush with the top of the viewport: the WordPress
		// admin bar pushes <html> down 32px for logged-in users. start.y is
		// measured relative to the panel, but the stage is viewport-fixed, so
		// without this the helmet renders that far above its band and lands on
		// the meta strip. Zero for logged-out visitors, and it ramps out as
		// soon as panel 1 reaches the top edge.
		const preRoll = Math.max( 0, scrollStart - window.scrollY ) / vh;

		rig.position.y = ( lerp( f.start.y, endYFrac(), e ) - preRoll * ( 1 - e ) ) * visibleH
			+ CENTROID_DROP * scale * e;

		pivot.rotation.y = ( MODEL_FRONT_YAW + lerp( START_YAW, END_YAW, r ) ) * DEG;

		// A whisper of tilt so it never reads as a flat cut-out.
		if ( ! reduceMotion ) {
			const t = performance.now() * 0.00035;
			pivot.rotation.x = Math.sin( t ) * 0.022;
			pivot.rotation.z = Math.cos( t * 0.8 ) * 0.014;
		}

		// Callouts appear once the helmet has all but reached the front pose.
		const settled = p > 0.88;
		if ( settled !== wasSettled ) {
			wasSettled = settled;
			hero.classList.toggle( 'db-settled', settled );
		}

		if ( snap ) renderer.render( scene, camera );
	}


	let running = true;
	let visible = true;

	// Cached: the panel list never changes, and tick() consults it every frame.
	const stageZone = heroPanels( hero );
	let stageHidden = false;
	let lastClip = '';

	/**
	 * The helmet belongs to panels 1 and 2. The stage is a fixed, full-viewport
	 * layer, so once panel 2 has scrolled past it would otherwise sit over
	 * section 3 - different content that has nothing to do with the model.
	 * Clamp it to its own two panels instead of relying on the helmet happening
	 * to be off-screen by then.
	 */
	function updateStageZone() {
		if ( ! stageZone.start || ! stageZone.end ) return true;

		const first = stageZone.start.getBoundingClientRect();
		const last  = stageZone.end.getBoundingClientRect();
		const inZone = last.bottom > 0 && first.top < vh;

		if ( inZone === stageHidden ) {
			stageHidden = ! inZone;
			stage.style.visibility = inZone ? '' : 'hidden';
		}

		if ( ! inZone ) return false;

		// Switching the layer off at the very end is not enough: while panel 2
		// is still partly on screen the helmet can hang below its bottom edge
		// and over whatever follows. Clip the fixed layer to the panels' own
		// bounds so the model is physically unable to paint outside them.
		const top    = Math.max( 0, Math.round( first.top ) );
		const bottom = Math.max( 0, Math.round( vh - last.bottom ) );
		const clip   = top || bottom ? `inset(${ top }px 0 ${ bottom }px 0)` : 'none';

		if ( clip !== lastClip ) {
			lastClip = clip;
			stage.style.clipPath = clip;
		}

		return true;
	}

	function tick() {
		if ( ! running ) return;
		requestAnimationFrame( tick );

		if ( ! visible || document.hidden || ! ready ) return;
		if ( ! updateStageZone() ) return;

		target = reduceMotion ? 1 : readProgress();
		eased  = reduceMotion ? 1 : lerp( eased, target, DAMPING );

		apply( eased, false );
		renderer.render( scene, camera );
	}

	requestAnimationFrame( tick );

	/*--------------------------------------------------*/
	/*  Wiring                                          */
	/*--------------------------------------------------*/

	// Only render while the hero is actually on screen - a fixed canvas would
	// otherwise keep burning frames over the rest of the page.
	if ( 'IntersectionObserver' in window ) {
		new IntersectionObserver(
			( entries ) => {
				visible = entries[ 0 ].isIntersecting;
				stage.style.visibility = visible ? '' : 'hidden';
			},
			{ rootMargin: '10% 0px' }
		).observe( hero );
	}

	let resizePending = false;

	function onResize() {
		if ( resizePending ) return;
		resizePending = true;
		requestAnimationFrame( () => {
			resizePending = false;
			layout();
		} );
	}

	if ( 'ResizeObserver' in window ) {
		new ResizeObserver( onResize ).observe( stage );
	} else {
		window.addEventListener( 'resize', onResize, { passive: true } );
	}

	// Rotating a phone fires before the new viewport is reported on some
	// browsers, so re-measure once the layout has settled too.
	window.addEventListener( 'orientationchange', () => setTimeout( layout, 150 ) );

	window.addEventListener( 'scroll', measureScrollIfStale, { passive: true } );

	let lastMeasure = 0;
	function measureScrollIfStale() {
		const now = performance.now();
		if ( now - lastMeasure < 500 ) return;
		lastMeasure = now;
		measureScroll();
	}

	renderer.domElement.addEventListener( 'webglcontextlost', ( e ) => {
		e.preventDefault();
		running = false;
		stage.classList.remove( 'is-ready' );
	} );

	renderer.domElement.addEventListener( 'webglcontextrestored', () => {
		running = true;
		layout();
		requestAnimationFrame( tick );
	} );

	layout();
}
