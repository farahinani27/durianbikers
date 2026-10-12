(function($) {
	
// -- GSAP -- //
	
document.addEventListener('DOMContentLoaded', function() {

	gsap.registerPlugin(ScrollTrigger, SplitText);


	// =========================================================
	// HEADER SCROLL BEHAVIOUR
	// =========================================================

	const header = document.querySelector('.custom-transparent-header');

	if (header) {

		let scrollTimer;

		header.classList.add('db-header-top');

		window.addEventListener('scroll', function() {

			const currentScrollY = window.scrollY;

			// At top → full header
			if (currentScrollY <= 10) {

				clearTimeout(scrollTimer);

				header.classList.remove(
					'db-header-hidden',
					'db-header-scrolled'
				);

				header.classList.add('db-header-top');

				return;
			}

			// Scrolling → hide header
			header.classList.remove('db-header-top');
			header.classList.add('db-header-hidden');

			// Stop scrolling → show logo + right content
			clearTimeout(scrollTimer);

			scrollTimer = setTimeout(function() {

				header.classList.remove('db-header-hidden');
				header.classList.add('db-header-scrolled');

			}, 250);

		}, { passive: true });
	}


	// --- SECTION 1 ---
	ScrollTrigger.create({
		trigger: '.stack',
		start: "0% 0%",
		end: "+=100%",
		scrub: true,
		pin: true,
		pinSpacing: false,
	});


	// --- SECTION 2 ---
	const section2 = gsap.timeline({
			scrollTrigger: {
				trigger: '.pinned',
				start: "0% 0%",
				end: "+=100%",
				scrub: 1,
				pin: true,
				pinSpacing: true,
				markers: false
			}
		});
		// Text reveal
		section2.to(".content-reveal", {
			opacity: 1,
			y: 0,
			duration: 1
		});
		
		// --- SECTION 3 ---
		const section3 = gsap.timeline({
			scrollTrigger: {
				trigger: '.third-container-pinned',
				start: "0% 0%",
				end: "+=100%",
				scrub: 1,
				pin: true,
				pinSpacing: true,
				markers: false
			}
		});
		// Text reveal
		section3.to(".third-content-reveal", {
			opacity: 1,
			y: 0,
			duration: 1
		});
		
		// --- SECTION 4 ---
		
		let gridCards3MM = gsap.matchMedia();

		gridCards3MM.add("(min-width: 1025px)", () => {

		  const gridTL3 = gsap.timeline({
			scrollTrigger: {
			  trigger: ".section-4",
			  start: "top top",
			  end: "+=100%",
			  scrub: 1,
			  pin: true,
			}
		  });

		  const tcaCardGrid = document.querySelectorAll(".card-grid");

		  const positions3 = [
			// ROW 1
			{ left: "0%", top: "0%", tx: "0%", ty: "0%" },
			{ left: "33.33%", top: "0%", tx: "-33.33%", ty: "0%" },
			{ left: "66.66%", top: "0%", tx: "-66.66%", ty: "0%" },
			{ left: "100%", top: "0%", tx: "-100%", ty: "0%" },

			// ROW 2
			{ left: "0%", top: "100%", tx: "0%", ty: "-100%" },
			{ left: "33.33%", top: "100%", tx: "-33.33%", ty: "-100%" },
			{ left: "66.66%", top: "100%", tx: "-66.66%", ty: "-100%" },
			{ left: "100%", top: "100%", tx: "-100%", ty: "-100%" },
		  ];

		  tcaCardGrid.forEach((card, i) => {
			const p = positions3[i];
			gridTL3.to(card, {
			  left: p.left,
			  top: p.top,
			  transform: `translate(${p.tx}, ${p.ty})`

			}, 0); // all animate at the same time
		  });

		});
		
		// --- SECTION 5 ---
		const section5 = gsap.timeline({
			scrollTrigger: {
				trigger: '.fifth-container-pinned',
				start: "0% 0%",
				end: "+=100%",
				scrub: 1,
				pin: true,
				pinSpacing: true,
// 				markers: true
			}
		});
		// Text reveal
		section5.to(".fifth-content-reveal", {
			opacity: 1,
			y: 0,
			duration: 1
		});
		
		// --- SECTION 6 ---
		
		gsap.set(".left-container p", {
			autoAlpha: 0,
			x: -30
		});

		gsap.set(".right-container p", {
			autoAlpha: 0,
			x: 30
		});
		
		// Split Text
		let split = SplitText.create(".split", {
			type: "words, chars"
		});

		// Section 6 Timeline
		const section6 = gsap.timeline({
			scrollTrigger: {
				trigger: ".section-6-pinned",
				start: "top top",
				end: "+=500%",
				scrub: 3,
				pin: true,
				pinSpacing: true,
				markers: false
			}
		});
		
		// Background transition
		section6.to(".section-6-pinned", {
			backgroundColor: "#000000",
			duration: 1
		}, 0);
		
		// Animate SplitText
		section6.from(split.chars, {
			y: 100,
			autoAlpha: 0,
			stagger: 0.05,
 			duration: 1
		});
		
		//helmet showcase reveal
		section6.to(".helmet-showcase", {
			opacity: 1,
			y: 0,
			// duration: 2
		});
		
		
		// Parallax
		section6.to(".snow-1", {
			y: -140,
			ease: "none"
		}, ">");
		
		section6.to(".snow-2", {
			y: -110,
			ease: "none"
		}, "<");
		
		section6.to(".left-container p", {
			autoAlpha: 1,
			x: 0,
			stagger: 0.15
		});

		section6.to(".right-container p", {
			autoAlpha: 1,
			x: 0,
			stagger: 0.15
		});
		
		section6.to(".snow-3", {
			y: -250,
			ease: "none",
			duration: 0.7
		}, "<");
		
		
		// --- Section 7 ---
		const section7 = gsap.timeline({
			scrollTrigger: {
				trigger: '.seventh-container-pinned',
				start: "0% 0%",
				end: "+=100%",
				scrub: 1,
				pin: true,
				pinSpacing: true,
				markers: false
			}
		});
		// Text reveal
		section7.to(".seventh-content-reveal", {
			opacity: 1,
			y: 0,
			duration: 1
		});

		
		// IMPORTANT
		window.addEventListener("load", () => {
			ScrollTrigger.refresh();
		});

		// VERY IMPORTANT FOR MOBILE
		window.addEventListener("resize", () => {
			ScrollTrigger.refresh();
		});
		


		
		
	});

	// Mobile Menu - Drilldown Submenu (Shop / Motorcycle Helmets)
    $(function() {
        var $list = $('#mobile-menu > ul.mobile-menu-list');
        if (!$list.length) return;

        $list.children('li.menu-item-has-children').each(function() {
            var $li = $(this);
            var $link = $li.children('a').first();
            var $sub = $li.children('ul.sub-menu').first();
            if (!$sub.length) return;

            $li.addClass('has-drilldown');

            var title = $link.text();
            var $arrow = $('<span class="submenu-toggle"><i class="fal fa-angle-right"></i></span>');
            $link.after($arrow);

            var $back = $('<li class="submenu-back"><i class="fal fa-angle-left"></i> Back</li>');
            var $title = $('<li class="submenu-title">' + title + '</li>');
            $sub.prepend($title);
            $sub.prepend($back);

            $arrow.on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $sub.addClass('active');
            });

            $back.on('click', function(e) {
                e.preventDefault();
                $sub.removeClass('active');
            });
        });

        // Reset submenus when the mobile menu is closed
        $('#hamburger-btn, #mobile-menu-close').on('click', function() {
            $list.find('ul.sub-menu').removeClass('active');
        });
    });
})(jQuery);

document.addEventListener('DOMContentLoaded', function () {
  var items = document.querySelectorAll('.all-category-item');
  if (!items.length) return;

  var container = items[0].parentElement;

  var btn = document.createElement('button');
  btn.id = 'view-more-categories';
  btn.textContent = 'View More';
  container.parentElement.insertBefore(btn, container.nextSibling);

  var shown = 6;

  if (items.length <= shown) {
    btn.style.display = 'none';
    return;
  }

  btn.addEventListener('click', function () {
    for (var i = shown; i < shown + 6 && i < items.length; i++) {
      items[i].classList.add('show-more-active');
    }
    shown += 6;
    if (shown >= items.length) {
      btn.style.display = 'none';
    }
  });
});