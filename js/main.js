/**
 * Hotel Website - Main JavaScript
 * Premium Interactions & Animations
 */

// Page Loader — hide quickly once everything is ready
window.addEventListener('load', function() {
    const loader = document.getElementById('page-loader');
    if (loader) {
        // Brief delay for paint, then reveal content
        setTimeout(() => {
            loader.classList.add('hidden');
        }, 200);
    }
});

// Also hide the loader when the page is shown after navigating back
window.addEventListener('pageshow', function(event) {
    // If the page was restored from the bfcache (back-forward cache), hide the loader
    if (event.persisted) {
        const loader = document.getElementById('page-loader');
        if (loader) {
            loader.classList.add('hidden');
        }
    }
});

// Show loader on page navigation (only for actual page changes, not hash links)
document.addEventListener('click', function(e) {
    const link = e.target.closest('a');
    if (link && link.href && !link.target && link.href.startsWith(window.location.origin)) {
        // Check if this is a hash link to the same page
        const url = new URL(link.href);
        const currentUrl = new URL(window.location.href);

        // If it's the same page with just a hash change, don't show loader
        if (url.pathname === currentUrl.pathname && url.hash) {
            return; // Don't show loader for same-page hash navigation
        }

        // Don't show loader if the link is prevented by other handlers (like smooth scroll)
        if (e.defaultPrevented) {
            return;
        }

        // Only show loader for actual page navigation (different path)
        if (url.pathname !== currentUrl.pathname) {
            const loader = document.getElementById('page-loader');
            if (loader) {
                loader.classList.remove('hidden');
            }
        }
    }
});

document.addEventListener('DOMContentLoaded', function() {
        // Room Featured Image AJAX Upload
        const imageUploadForm = document.getElementById('imageUploadForm');
        const currentImage = document.getElementById('currentImage');
        const currentImageContainer = document.getElementById('currentImageContainer');
        if (imageUploadForm) {
            imageUploadForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(imageUploadForm);
                fetch('room-management.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(async res => {
                    let text = await res.text();
                    try {
                        let data = JSON.parse(text);
                        if (data.success && data.image_url) {
                            if (currentImage) {
                                currentImage.src = '../' + data.image_url;
                                currentImageContainer.style.display = 'block';
                            }
                            alert('Image uploaded successfully!');
                        } else {
                            alert(data.message || 'Upload failed.');
                        }
                    } catch (err) {
                        console.error('Invalid JSON response:', text);
                        alert('Upload failed. Server error or invalid response.');
                    }
                })
                .catch((err) => {
                    console.error('Network or JS error:', err);
                    alert('Upload failed. Network or server error.');
                });
            });
        }
    
    // Time and Temperature Widget
    function updateTimeAndTemp() {
        const timeParts = new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Africa/Blantyre',
            hour12: true,
            hour: '2-digit',
            minute: '2-digit'
        }).formatToParts(new Date());

        const hours = timeParts.find(p => p.type === 'hour')?.value || '--';
        const minutes = timeParts.find(p => p.type === 'minute')?.value || '--';
        const ampm = (timeParts.find(p => p.type === 'dayPeriod')?.value || 'AM').toUpperCase();
        
        // Desktop widget
        const timeDisplay = document.getElementById('heroTime');
        const ampmDisplay = document.getElementById('heroAmpm');
        
        if (timeDisplay) timeDisplay.textContent = `${hours}:${minutes}`;
        if (ampmDisplay) ampmDisplay.textContent = ampm;
        
        // Mobile widget
        const timeDisplayMobile = document.getElementById('heroTimeMobile');
        const ampmDisplayMobile = document.getElementById('heroAmpmMobile');
        
        if (timeDisplayMobile) timeDisplayMobile.textContent = `${hours}:${minutes}`;
        if (ampmDisplayMobile) ampmDisplayMobile.textContent = ampm;
        
        // Update Temperature (simulated with random value for demo)
        const tempDisplay = document.getElementById('heroTemp');
        const tempDisplayMobile = document.getElementById('heroTempMobile');
        
        if (tempDisplay || tempDisplayMobile) {
            const temp = Math.round(22 + Math.random() * 8); // 22-30°C range
            if (tempDisplay) tempDisplay.textContent = `${temp}°C`;
            if (tempDisplayMobile) tempDisplayMobile.textContent = `${temp}°C`;
        }
    }
    
    // Initial update
    updateTimeAndTemp();
    
    // Update every minute
    setInterval(updateTimeAndTemp, 60000);
    
    // Smooth scrolling for ALL internal links (navigation, footer, CTA buttons, etc.)
    function initSmoothScrolling() {
        // Select all links that contain hash fragments
        const allLinks = document.querySelectorAll('a[href*="#"]');
        
        allLinks.forEach(link => {
            // Remove any existing click listeners to avoid duplicates
            link.removeEventListener('click', handleSmoothScroll);
            link.addEventListener('click', handleSmoothScroll);
        });
    }
    
    function handleSmoothScroll(e) {
        const href = this.getAttribute('href');

        // Skip if it's just a hash or empty
        if (href === '#' || href === '') return;

        // Extract the hash part from the href
        let targetId = href;
        if (href.includes('#')) {
            targetId = '#' + href.split('#')[1];
        }

        // Skip if no hash found
        if (targetId === '#') return;

        // Check if target exists on current page
        const targetSection = document.querySelector(targetId);
        if (!targetSection) {
            // If target doesn't exist on this page, let the link work normally
            return;
        }

        // Check if this is a link to the current page (index.php#rooms)
        const isCurrentPageLink = href.startsWith(window.location.pathname) ||
                                 href.startsWith('index.php') ||
                                 (href.includes('#') && !href.includes('http'));

        if (isCurrentPageLink) {
            // Prevent default for same-page anchors
            e.preventDefault();

            // Calculate scroll position with header offset
            const headerOffset = 80;
            const elementPosition = targetSection.getBoundingClientRect().top;
            const offsetPosition = elementPosition + window.pageYOffset - headerOffset;

            // Smooth scroll to target
            window.scrollTo({
                top: offsetPosition,
                behavior: 'smooth'
            });

            // Update URL hash without page jump
            if (history.pushState) {
                history.pushState(null, null, targetId);
            } else {
                window.location.hash = targetId;
            }

            // Special handling for contact link - highlight the contact information
            if (targetId === '#contact') {
                // Add temporary highlight effect to contact information specifically
                const contactInfo = targetSection.querySelector('.minimalist-contact-info') ||
                                  targetSection.querySelector('.contact-info') ||
                                  targetSection.querySelector('ul') ||
                                  targetSection;
                contactInfo.classList.add('contact-highlighted');
                setTimeout(() => {
                    contactInfo.classList.remove('contact-highlighted');
                }, 2000); // Remove highlight after 2 seconds
            }

            // Close mobile menu if open
            const navMenu = document.querySelector('.nav-menu');
            if (navMenu && navMenu.classList.contains('active')) {
                navMenu.classList.remove('active');
                document.querySelector('.mobile-menu-btn')?.classList.remove('active');
                document.querySelector('.mobile-menu-overlay')?.classList.remove('active');
                document.body.style.overflow = '';
            }
        }
        // If it's not a current page link, let it work normally (external link)
    }

    // Handle browser back/forward buttons to prevent loader issues
    window.addEventListener('popstate', function(event) {
        // Don't show loader when navigating with browser back/forward buttons
        // Allow normal navigation to occur

        // Optionally, scroll to the section corresponding to the hash in the URL
        setTimeout(() => {
            if (window.location.hash) {
                const targetElement = document.querySelector(window.location.hash);
                if (targetElement) {
                    const headerOffset = 80;
                    const elementPosition = targetElement.getBoundingClientRect().top;
                    const offsetPosition = elementPosition + window.pageYOffset - headerOffset;

                    window.scrollTo({
                        top: offsetPosition,
                        behavior: 'smooth'
                    });
                }
            }
        }, 100); // Small delay to ensure DOM is ready
    });
    
    // Initialize smooth scrolling on page load
    initSmoothScrolling();
    
    // Re-initialize when new content is added dynamically
    const smoothScrollObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length) {
                initSmoothScrolling();
            }
        });
    });
    
    // Start observing the document body for added nodes
    smoothScrollObserver.observe(document.body, { childList: true, subtree: true });

    
    // Supreme Premium Header Scroll Effect
    const header = document.querySelector('.header');
    let lastScroll = 0;
    
    window.addEventListener('scroll', function() {
        const currentScroll = window.pageYOffset;
        
        if (currentScroll > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
        
        lastScroll = currentScroll;
    });
    
    // Active nav link on scroll - DISABLED
    // PHP now handles active states server-side based on current page
    // JavaScript scroll-based highlighting disabled to prevent conflicts
    // The is_nav_active() function in header.php sets the correct active class
    
    // Intersection Observer for fade-in animations
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const intersectionObserver = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
                intersectionObserver.unobserve(entry.target);
            }
        });
    }, observerOptions);
    
    // Observe all cards
    const cards = document.querySelectorAll('.room-card, .facility-card, .testimonial-card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        card.style.transition = `all 0.6s ease ${index * 0.1}s`;
        intersectionObserver.observe(card);
    });

    // Scroll to Top Button
    const scrollToTopBtn = document.getElementById('scrollToTop');
    
    if (scrollToTopBtn) {
        // Show/hide button based on scroll position
        window.addEventListener('scroll', function() {
            if (window.pageYOffset > 300) {
                scrollToTopBtn.classList.add('visible');
            } else {
                scrollToTopBtn.classList.remove('visible');
            }
        });
        
        // Scroll to top on click
        scrollToTopBtn.addEventListener('click', function() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }
    
    // Mobile menu functionality
    const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    const navMenu = document.querySelector('.nav-menu');
    const mobileMenuOverlay = document.querySelector('.mobile-menu-overlay');

    if (mobileMenuBtn && navMenu) {
        const setMenuOpen = (open) => {
            navMenu.classList.toggle('active', open);
            mobileMenuBtn.classList.toggle('active', open);
            if (mobileMenuOverlay) mobileMenuOverlay.classList.toggle('active', open);

            mobileMenuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            mobileMenuBtn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');

            document.body.style.overflow = open ? 'hidden' : '';

            if (open) {
                // Ensure menu is at top of screen and visible
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        };

        const isMenuOpen = () => navMenu.classList.contains('active');

        // Primary toggle
        mobileMenuBtn.addEventListener('click', function() {
            setMenuOpen(!isMenuOpen());
        });

        // Close menu when clicking overlay
        if (mobileMenuOverlay) {
            mobileMenuOverlay.addEventListener('click', function() {
                setMenuOpen(false);
            });
        }

        // Close menu when clicking on a link
        navMenu.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', function() {
                setMenuOpen(false);
            });
        });

        // Close on ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isMenuOpen()) setMenuOpen(false);
        });

        // Safety: if we resize to desktop, force-close and unlock scroll
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1191 && isMenuOpen()) setMenuOpen(false);
        });
    }
    
    // Hero carousel
    const heroSlides = document.querySelectorAll('.hero-slide');
    const heroIndicators = document.querySelectorAll('.hero-indicator');
    const prevBtns = document.querySelectorAll('.hero-prev');
    const nextBtns = document.querySelectorAll('.hero-next');
    let heroIndex = 0;
    let heroTimer;

    function setHeroSlide(index) {
        if (!heroSlides.length) return;
        heroIndex = (index + heroSlides.length) % heroSlides.length;
        heroSlides.forEach((slide, i) => {
            slide.classList.toggle('active', i === heroIndex);
        });
        heroIndicators.forEach((dot, i) => {
            dot.classList.toggle('active', i === heroIndex);
        });
        // Lazy-load: apply background-image from data-bg for active + next slide
        loadHeroBg(heroIndex);
        loadHeroBg((heroIndex + 1) % heroSlides.length);
    }

    // Load a hero slide's background image from data-bg attribute
    function loadHeroBg(idx) {
        var slide = heroSlides[idx];
        if (!slide) return;
        var imgDiv = slide.querySelector('.hero-slide-image[data-bg]');
        if (imgDiv) {
            imgDiv.style.backgroundImage = "url('" + imgDiv.getAttribute('data-bg') + "')";
            imgDiv.removeAttribute('data-bg');
        }
    }

    function nextHeroSlide() {
        setHeroSlide(heroIndex + 1);
    }

    function prevHeroSlideFn() {
        setHeroSlide(heroIndex - 1);
    }

    function startHeroAuto() {
        if (heroTimer) clearInterval(heroTimer);
        heroTimer = setInterval(nextHeroSlide, 6000);
    }

    if (heroSlides.length) {
        startHeroAuto();
        setHeroSlide(0);

        heroIndicators.forEach(dot => {
            dot.addEventListener('click', () => {
                const index = parseInt(dot.dataset.index, 10);
                setHeroSlide(index);
                startHeroAuto();
            });
        });

        nextBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                nextHeroSlide();
                startHeroAuto();
            });
        });

        prevBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                prevHeroSlideFn();
                startHeroAuto();
            });
        });

        // Pause on hover (desktop)
        const heroSection = document.querySelector('.hero');
        if (heroSection) {
            heroSection.addEventListener('mouseenter', () => heroTimer && clearInterval(heroTimer));
            heroSection.addEventListener('mouseleave', startHeroAuto);
        }
    }
    
    // Add hover effect to room cards
    const roomCards = document.querySelectorAll('.room-card');
    roomCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-10px) scale(1.02)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });
    
    // Lazy loading for images
    const images = document.querySelectorAll('img[data-src]');
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src;
                img.removeAttribute('data-src');
                imageObserver.unobserve(img);
            }
        });
    });
    
    images.forEach(img => imageObserver.observe(img));

    // Policy modals - Using new Modal component
    const policyLinks = document.querySelectorAll('.policy-link');
    const policyOverlay = document.querySelector('[data-policy-overlay]');
    
    function openPolicy(slug) {
        const modalId = 'policy-' + slug;
        const modal = document.getElementById(modalId);
        
        if (typeof Modal !== 'undefined' && Modal.open) {
            Modal.open(modalId);
        } else {
            console.error('Modal component not available');
        }
    }
    
    policyLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const slug = this.dataset.policy;
            openPolicy(slug);
        });
    });
    
    if (policyOverlay) {
        policyOverlay.addEventListener('click', function() {
            if (typeof Modal !== 'undefined' && Modal.closeAll) {
                Modal.closeAll();
            }
        });
    }

    // Room Detail Carousel - DISABLED (Using Grid Layout Instead)
    // The carousel functionality has been replaced with a modern grid layout
    // in rooms-showcase.php. Keep this code commented out for reference.
    /*
    const roomCarousel = document.getElementById('roomCarousel');
    if (roomCarousel) {
        const slides = roomCarousel.querySelectorAll('.carousel-slide');
        const carouselDots = document.getElementById('carouselDots');
        const prevBtn = roomCarousel.querySelector('.carousel-prev');
        const nextBtn = roomCarousel.querySelector('.carousel-next');
        
        let currentSlide = 0;

        // Create dots
        slides.forEach((_, index) => {
            const dot = document.createElement('button');
            dot.className = `carousel-dot ${index === 0 ? 'active' : ''}`;
            dot.setAttribute('aria-label', `Go to slide ${index + 1}`);
            dot.addEventListener('click', () => goToSlide(index));
            carouselDots.appendChild(dot);
        });

        function updateCarousel() {
            slides.forEach(slide => slide.classList.remove('active'));
            carouselDots.querySelectorAll('.carousel-dot').forEach(dot => dot.classList.remove('active'));
            
            slides[currentSlide].classList.add('active');
            carouselDots.children[currentSlide].classList.add('active');
        }

        function goToSlide(index) {
            currentSlide = (index + slides.length) % slides.length;
            updateCarousel();
        }

        function nextSlide() {
            currentSlide = (currentSlide + 1) % slides.length;
            updateCarousel();
        }

        function prevSlide() {
            currentSlide = (currentSlide - 1 + slides.length) % slides.length;
            updateCarousel();
        }

        if (prevBtn) prevBtn.addEventListener('click', prevSlide);
        if (nextBtn) nextBtn.addEventListener('click', nextSlide);

        // Keyboard navigation
        document.addEventListener('keydown', (e) => {
            if (roomCarousel.offsetParent !== null) { // Only if visible
                if (e.key === 'ArrowLeft') prevSlide();
                if (e.key === 'ArrowRight') nextSlide();
            }
        });

        // Auto-advance carousel every 6 seconds
        setInterval(nextSlide, 6000);
    }
    */

    // Room Filter Chips
    const filterChips = document.querySelectorAll('.chip');
    const roomTiles = document.querySelectorAll('.room-tile');

    if (filterChips.length > 0 && roomTiles.length > 0) {
        filterChips.forEach(chip => {
            chip.addEventListener('click', function() {
                const filterValue = this.getAttribute('data-filter');
                
                // Update active chip
                filterChips.forEach(c => c.classList.remove('active'));
                this.classList.add('active');

                // Filter rooms
                roomTiles.forEach(tile => {
                    const tileFilter = tile.getAttribute('data-filter');
                    
                    // Show/hide based on filter
                    if (filterValue === 'all-rooms' || tileFilter.includes(filterValue)) {
                        tile.style.display = '';
                        // Trigger animation
                        setTimeout(() => {
                            tile.style.opacity = '1';
                            tile.style.transform = 'translateY(0)';
                        }, 10);
                    } else {
                        tile.style.opacity = '0';
                        tile.style.transform = 'translateY(10px)';
                        setTimeout(() => {
                            tile.style.display = 'none';
                        }, 300);
                    }
                });
            });
        });

        // Add transition styles to room tiles
        roomTiles.forEach(tile => {
            tile.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            tile.style.opacity = '1';
            tile.style.transform = 'translateY(0)';
        });
    }
    
    // Hotel Gallery Carousel
    const galleryCarousel = document.querySelector('.gallery-carousel-track');
    const galleryItems = document.querySelectorAll('.gallery-carousel-item');
    const galleryPrevBtn = document.querySelector('.gallery-nav-prev');
    const galleryNextBtn = document.querySelector('.gallery-nav-next');
    const galleryDots = document.querySelectorAll('.gallery-dot');
    
    if (galleryCarousel && galleryItems.length > 0) {
        let currentGalleryIndex = 0;
        let itemsToShow = getItemsToShow();
        
        function getItemsToShow() {
            if (window.innerWidth <= 768) return 1;
            if (window.innerWidth <= 1024) return 3;
            return 4;
        }
        
        function updateCarousel(smooth = true) {
            const itemWidth = galleryItems[0].offsetWidth;
            const gap = 20;
            const offset = currentGalleryIndex * (itemWidth + gap);
            
            galleryCarousel.style.transition = smooth ? 'transform 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94)' : 'none';
            galleryCarousel.style.transform = `translateX(-${offset}px)`;
            
            // Update active dot
            galleryDots.forEach((dot, index) => {
                dot.classList.toggle('active', index === currentGalleryIndex);
            });
        }
        
        function nextSlide() {
            const maxIndex = Math.max(0, galleryItems.length - itemsToShow);
            currentGalleryIndex = Math.min(currentGalleryIndex + 1, maxIndex);
            updateCarousel();
        }
        
        function prevSlide() {
            currentGalleryIndex = Math.max(currentGalleryIndex - 1, 0);
            updateCarousel();
        }
        
        // Navigation buttons
        if (galleryNextBtn) {
            galleryNextBtn.addEventListener('click', nextSlide);
        }
        
        if (galleryPrevBtn) {
            galleryPrevBtn.addEventListener('click', prevSlide);
        }
        
        // Dot navigation
        galleryDots.forEach((dot, index) => {
            dot.addEventListener('click', () => {
                currentGalleryIndex = index;
                updateCarousel();
            });
        });
        
        // Auto-play carousel
        let autoplayInterval = setInterval(nextSlide, 4000);
        
        // Pause on hover
        const carouselWrapper = document.querySelector('.gallery-carousel-wrapper');
        if (carouselWrapper) {
            carouselWrapper.addEventListener('mouseenter', () => {
                clearInterval(autoplayInterval);
            });
            
            carouselWrapper.addEventListener('mouseleave', () => {
                autoplayInterval = setInterval(nextSlide, 4000);
            });
        }
        
        // Responsive resize handler
        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                const newItemsToShow = getItemsToShow();
                if (newItemsToShow !== itemsToShow) {
                    itemsToShow = newItemsToShow;
                    currentGalleryIndex = Math.min(currentGalleryIndex, Math.max(0, galleryItems.length - itemsToShow));
                    updateCarousel(false);
                }
            }, 250);
        });
        
        // Touch/swipe support
        let touchStartX = 0;
        let touchEndX = 0;
        
        if (carouselWrapper) {
            carouselWrapper.addEventListener('touchstart', (e) => {
                touchStartX = e.touches[0].clientX;
            }, { passive: true });
            
            carouselWrapper.addEventListener('touchend', (e) => {
                touchEndX = e.changedTouches[0].clientX;
                const swipeDistance = touchStartX - touchEndX;
                
                if (Math.abs(swipeDistance) > 50) {
                    if (swipeDistance > 0) {
                        nextSlide();
                    } else {
                        prevSlide();
                    }
                }
            }, { passive: true });
        }
        
        // Initialize
        updateCarousel(false);
    }
});
