// ============================================
// 📦 MR NEON - سیستم کامل تعاملی
// ============================================

'use strict';

// 🔧 Configuration
const CONFIG = {
    email: 'contact@mrneon.dev',
    telegram: '@MR_NEON_TEAM',
    telegramLink: 'https://t.me/MR_NEON_TEAM',
    telegramAppLink: 'tg://resolve?domain=MR_NEON_TEAM',
    apiEndpoint: 'https://api.mrneon.dev/contact',
    particleCount: 50,
    scrollThreshold: 100,
    modalAnimationDuration: 300,
    toastDuration: 3000
};

// 🎮 State Management
class AppState {
    constructor() {
        this.isMobileMenuOpen = false;
        this.isModalOpen = false;
        this.isFormSubmitting = false;
        this.currentFilter = 'all';
        this.scrollPosition = 0;
        this.particles = [];
        this.intersectionObservers = new Map();
        this.activeAnimations = new Set();
        this.formData = {
            name: '',
            email: '',
            projectType: '',
            message: ''
        };
    }

    resetForm() {
        this.formData = {
            name: '',
            email: '',
            projectType: '',
            message: ''
        };
        this.isFormSubmitting = false;
    }
}

const state = new AppState();

// 🎯 DOM Elements
const DOM = {
    // Core Elements
    body: document.body,
    html: document.documentElement,
    
    // Navigation
    navbar: document.getElementById('navbar'),
    navMenu: document.getElementById('navMenu'),
    hamburger: document.getElementById('hamburger'),
    navLinks: document.querySelectorAll('.nav-link'),
    
    // Sections
    sections: {
        hero: document.getElementById('hero'),
        stats: document.getElementById('stats'),
        services: document.getElementById('services'),
        portfolio: document.getElementById('portfolio'),
        team: document.getElementById('team'),
        tech: document.getElementById('tech'),
        contact: document.getElementById('contact')
    },
    
    // Stats
    statCards: document.querySelectorAll('.stat-card'),
    statCounters: document.querySelectorAll('.stat-card .count'),
    
    // Portfolio
    portfolioGrid: document.getElementById('portfolioGrid'),
    portfolioFilters: document.getElementById('portfolioFilters'),
    filterButtons: document.querySelectorAll('.filter-btn'),
    viewMoreProjects: document.getElementById('viewMoreProjects'),
    
    // Modal
    projectModal: document.getElementById('projectModal'),
    modalClose: document.getElementById('modalClose'),
    modalBody: document.getElementById('modalBody'),
    
    // Contact
    contactForm: document.getElementById('contactForm'),
    submitBtn: document.getElementById('submitBtn'),
    submitLoader: document.getElementById('submitLoader'),
    copyButtons: document.querySelectorAll('.copy-btn'),
    telegramBtn: document.getElementById('telegramBtn'),
    
    // Form Fields & Errors
    formFields: {
        name: document.getElementById('name'),
        email: document.getElementById('email'),
        projectType: document.getElementById('projectType'),
        message: document.getElementById('message')
    },
    formErrors: {
        name: document.getElementById('nameError'),
        email: document.getElementById('emailError'),
        projectType: document.getElementById('projectTypeError'),
        message: document.getElementById('messageError')
    },
    
    // UI Elements
    loadingScreen: document.getElementById('loadingScreen'),
    backToTop: document.getElementById('backToTop'),
    particlesContainer: document.getElementById('particles'),
    marqueeTrack: document.getElementById('marqueeTrack'),
    toastContainer: document.getElementById('toastContainer'),
    
    // Service Cards
    serviceCards: document.querySelectorAll('.service-card'),
    
    // Team Cards
    teamCards: document.querySelectorAll('.team-card')
};

// 📊 Project Data
const PROJECTS = {
    1: {
        id: 1,
        title: 'فروشگاه مد و زیبایی',
        category: 'ecommerce',
        categoryLabel: '🛒 فروشگاهی',
        description: 'طراحی کامل فروشگاه آنلاین با سیستم مدیریت محصولات پیشرفته، درگاه پرداخت امن و پنل مدیریت حرفه‌ای.',
        fullDescription: 'این پروژه یک فروشگاه آنلاین کامل برای یک برند مد و زیبایی است که شامل تمامی امکانات مورد نیاز برای فروش آنلاین می‌باشد. سیستم طراحی شده قابلیت مدیریت هزاران محصول، تنوع رنگ و سایز، سیستم کوپن تخفیف، گزارش‌گیری پیشرفته و اتصال به درگاه‌های پرداخت معتبر را داراست.',
        technologies: ['React', 'Node.js', 'MongoDB', 'Redux', 'Stripe API'],
        duration: '۴۵ روز',
        services: ['طراحی UI/UX', 'توسعه فروشگاه', 'اتصال درگاه پرداخت', 'بهینه‌سازی سئو'],
        demoUrl: 'https://demo.mrneon.dev/fashion-store',
        images: [
            'assets/images/portfolio/fashion-1.jpg',
            'assets/images/portfolio/fashion-2.jpg',
            'assets/images/portfolio/fashion-3.jpg'
        ]
    },
    2: {
        id: 2,
        title: 'پلتفرم آموزش آنلاین',
        category: 'webapp',
        categoryLabel: '📱 وب‌اپ',
        description: 'پلتفرم تعاملی آموزش با سیستم ویدیو، تمرین و آزمون',
        fullDescription: 'یک پلتفرم آموزش آنلاین جامع که امکان برگزاری دوره‌های آموزشی، کلاس‌های زنده، سیستم آزمون آنلاین و رتبه‌بندی دانش‌آموزان را فراهم می‌کند. این سیستم شامل پنل مدرس، دانش‌آموز و مدیر است.',
        technologies: ['Vue.js', 'Laravel', 'MySQL', 'WebRTC', 'Socket.io'],
        duration: '۶۰ روز',
        services: ['تحلیل نیازها', 'طراحی معماری', 'توسعه فرانت‌اند و بک‌اند', 'تست و راه‌اندازی'],
        demoUrl: 'https://demo.mrneon.dev/learning-platform',
        images: [
            'assets/images/portfolio/education-1.jpg',
            'assets/images/portfolio/education-2.jpg',
            'assets/images/portfolio/education-3.jpg'
        ]
    },
    3: {
        id: 3,
        title: 'سایت شرکتی فناوری',
        category: 'corporate',
        categoryLabel: '🏢 شرکتی',
        description: 'طراحی مدرن برای معرفی خدمات شرکت فناوری',
        fullDescription: 'وب‌سایت شرکتی با طراحی مدرن و رابط کاربری پیشرفته برای یک شرکت فناوری. این سایت شامل بخش‌های معرفی خدمات، تیم، نمونه‌کارها، مقالات تخصصی و سیستم تماس است.',
        technologies: ['Next.js', 'TypeScript', 'Tailwind CSS', 'GraphQL', 'Contentful'],
        duration: '۳۰ روز',
        services: ['طراحی UI/UX اختصاصی', 'توسعه با Next.js', 'بهینه‌سازی سئو', 'پیاده‌سازی CMS'],
        demoUrl: 'https://demo.mrneon.dev/tech-company',
        images: [
            'assets/images/portfolio/corporate-1.jpg',
            'assets/images/portfolio/corporate-2.jpg',
            'assets/images/portfolio/corporate-3.jpg'
        ]
    },
    4: {
        id: 4,
        title: 'وب‌اپ مدیریت پروژه',
        category: 'webapp',
        categoryLabel: '🔧 وب‌اپ',
        description: 'اپلیکیشن تحت وب برای مدیریت تیم و تسک‌ها',
        fullDescription: 'یک سیستم مدیریت پروژه کامل با قابلیت ایجاد پروژه، تسک، تایم‌لاین، چت تیمی، آپلود فایل و گزارش‌گیری. این سیستم برای تیم‌های توسعه نرم‌افزار بهینه شده است.',
        technologies: ['React', 'Express.js', 'PostgreSQL', 'Socket.io', 'JWT'],
        duration: '۷۵ روز',
        services: ['تحلیل فرآیندها', 'طراحی دیتابیس', 'توسعه API', 'پیاده‌سازی Real-time'],
        demoUrl: 'https://demo.mrneon.dev/project-management',
        images: [
            'assets/images/portfolio/pm-1.jpg',
            'assets/images/portfolio/pm-2.jpg',
            'assets/images/portfolio/pm-3.jpg'
        ]
    },
    5: {
        id: 5,
        title: 'سایت رستوران لوکس',
        category: 'ecommerce',
        categoryLabel: '🛒 فروشگاهی',
        description: 'سایت رزرو آنلاین با منوی دیجیتال و سیستم پرداخت',
        fullDescription: 'وب‌سایت رستوران لوکس با سیستم رزرو آنلاین، منوی دیجیتال، نمایش زنده میزهای خالی و سیستم پرداخت یکپارچه. همچنین شامل پنل مدیریت برای مدیریت سفارشات و مشتریان است.',
        technologies: ['WordPress', 'WooCommerce', 'PHP', 'MySQL', 'Stripe'],
        duration: '۲۵ روز',
        services: ['طراحی قالب اختصاصی', 'پیاده‌سازی WooCommerce', 'اتصال درگاه پرداخت', 'بهینه‌سازی موبایل'],
        demoUrl: 'https://demo.mrneon.dev/restaurant',
        images: [
            'assets/images/portfolio/restaurant-1.jpg',
            'assets/images/portfolio/restaurant-2.jpg',
            'assets/images/portfolio/restaurant-3.jpg'
        ]
    }
};

// 🎨 Animation System
class AnimationSystem {
    static async fadeIn(element, duration = 300, delay = 0) {
        return new Promise(resolve => {
            element.style.opacity = '0';
            element.style.display = 'block';
            
            setTimeout(() => {
                element.style.transition = `opacity ${duration}ms ease`;
                element.style.opacity = '1';
                
                setTimeout(() => {
                    element.style.transition = '';
                    resolve();
                }, duration);
            }, delay);
        });
    }
    
    static async fadeOut(element, duration = 300, delay = 0) {
        return new Promise(resolve => {
            setTimeout(() => {
                element.style.transition = `opacity ${duration}ms ease`;
                element.style.opacity = '0';
                
                setTimeout(() => {
                    element.style.display = 'none';
                    element.style.transition = '';
                    resolve();
                }, duration);
            }, delay);
        });
    }
    
    static async slideIn(element, direction = 'right', duration = 300) {
        const directions = {
            right: { from: '-100%', to: '0' },
            left: { from: '100%', to: '0' },
            top: { from: '-100px', to: '0' },
            bottom: { from: '100px', to: '0' }
        };
        
        const dir = directions[direction];
        element.style.transform = `translate(${dir.from})`;
        element.style.opacity = '0';
        element.style.display = 'block';
        
        return new Promise(resolve => {
            setTimeout(() => {
                element.style.transition = `transform ${duration}ms ease, opacity ${duration}ms ease`;
                element.style.transform = `translate(${dir.to})`;
                element.style.opacity = '1';
                
                setTimeout(() => {
                    element.style.transition = '';
                    resolve();
                }, duration);
            }, 10);
        });
    }
    
    static async slideOut(element, direction = 'right', duration = 300) {
        const directions = {
            right: { to: '-100%' },
            left: { to: '100%' },
            top: { to: '-100px' },
            bottom: { to: '100px' }
        };
        
        const dir = directions[direction];
        element.style.transition = `transform ${duration}ms ease, opacity ${duration}ms ease`;
        element.style.transform = `translate(${dir.to})`;
        element.style.opacity = '0';
        
        return new Promise(resolve => {
            setTimeout(() => {
                element.style.display = 'none';
                element.style.transition = '';
                resolve();
            }, duration);
        });
    }
    
    static async staggerChildren(container, animationFn, staggerDelay = 100) {
        const children = Array.from(container.children);
        const promises = [];
        
        for (let i = 0; i < children.length; i++) {
            promises.push(
                new Promise(resolve => {
                    setTimeout(async () => {
                        await animationFn(children[i]);
                        resolve();
                    }, i * staggerDelay);
                })
            );
        }
        
        return Promise.all(promises);
    }
    
    static createCounterAnimation(element, target, duration = 2000) {
        return new Promise(resolve => {
            const start = 0;
            const increment = target / (duration / 16);
            let current = start;
            
            const timer = setInterval(() => {
                current += increment;
                if (current >= target) {
                    clearInterval(timer);
                    element.textContent = Math.floor(target);
                    resolve();
                } else {
                    element.textContent = Math.floor(current);
                }
            }, 16);
        });
    }
}

// ✨ Particle System
class ParticleSystem {
    constructor(container) {
        this.container = container;
        this.particles = [];
        this.animationId = null;
    }
    
    createParticle() {
        const particle = document.createElement('div');
        particle.className = 'particle';
        
        const size = Math.random() * 4 + 1;
        const x = Math.random() * 100;
        const y = Math.random() * 100;
        
        particle.style.cssText = `
            position: absolute;
            width: ${size}px;
            height: ${size}px;
            background: linear-gradient(45deg, var(--neon), var(--primary));
            border-radius: 50%;
            left: ${x}%;
            top: ${y}%;
            opacity: ${Math.random() * 0.5 + 0.1};
            filter: blur(1px);
            pointer-events: none;
        `;
        
        return {
            element: particle,
            x: x,
            y: y,
            speedX: (Math.random() - 0.5) * 0.2,
            speedY: (Math.random() - 0.5) * 0.2,
            size: size
        };
    }
    
    init(count = CONFIG.particleCount) {
        for (let i = 0; i < count; i++) {
            const particle = this.createParticle();
            this.container.appendChild(particle.element);
            this.particles.push(particle);
        }
        
        this.start();
    }
    
    start() {
        const animate = () => {
            this.particles.forEach(particle => {
                particle.x += particle.speedX;
                particle.y += particle.speedY;
                
                if (particle.x > 100) particle.x = 0;
                if (particle.x < 0) particle.x = 100;
                if (particle.y > 100) particle.y = 0;
                if (particle.y < 0) particle.y = 100;
                
                particle.element.style.left = `${particle.x}%`;
                particle.element.style.top = `${particle.y}%`;
                
                const opacity = 0.3 + Math.sin(Date.now() * 0.001 + particle.x) * 0.2;
                particle.element.style.opacity = opacity;
            });
            
            this.animationId = requestAnimationFrame(animate);
        };
        
        animate();
    }
    
    stop() {
        if (this.animationId) {
            cancelAnimationFrame(this.animationId);
        }
    }
    
    destroy() {
        this.stop();
        this.particles.forEach(particle => {
            if (particle.element.parentNode === this.container) {
                this.container.removeChild(particle.element);
            }
        });
        this.particles = [];
    }
}

// 📊 Portfolio System
class PortfolioSystem {
    constructor() {
        this.grid = DOM.portfolioGrid;
        this.filters = DOM.filterButtons;
        this.viewMore = DOM.viewMoreProjects;
        this.currentFilter = 'all';
    }
    
    init() {
        this.setupFilters();
        this.setupViewMore();
        this.setupProjectDetails();
    }
    
    setupFilters() {
        this.filters.forEach(button => {
            button.addEventListener('click', () => {
                const filter = button.dataset.filter;
                this.filterProjects(filter);
                this.updateActiveFilter(button);
            });
        });
    }
    
    filterProjects(filter) {
        this.currentFilter = filter;
        const cards = this.grid.querySelectorAll('.portfolio-card:not(.view-more)');
        
        cards.forEach(card => {
            const category = card.dataset.category;
            
            if (filter === 'all' || category === filter) {
                AnimationSystem.fadeIn(card, 300).then(() => {
                    card.style.display = 'block';
                });
            } else {
                AnimationSystem.fadeOut(card, 300).then(() => {
                    card.style.display = 'none';
                });
            }
        });
    }
    
    updateActiveFilter(activeButton) {
        this.filters.forEach(button => {
            button.classList.remove('active');
        });
        activeButton.classList.add('active');
    }
    
    setupViewMore() {
        if (this.viewMore) {
            this.viewMore.addEventListener('click', () => {
                DOM.sections.contact.scrollIntoView({ behavior: 'smooth' });
            });
        }
    }
    
    setupProjectDetails() {
        const detailButtons = document.querySelectorAll('.view-details');
        
        detailButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                e.stopPropagation();
                const projectId = button.dataset.project;
                this.showProjectDetails(projectId);
            });
        });
    }
    
    async showProjectDetails(projectId) {
        const project = PROJECTS[projectId];
        if (!project) return;
        
        state.isModalOpen = true;
        DOM.body.style.overflow = 'hidden';
        
        const modalContent = this.createModalContent(project);
        DOM.modalBody.innerHTML = modalContent;
        
        DOM.projectModal.classList.add('active');
        await AnimationSystem.fadeIn(DOM.projectModal.querySelector('.modal-overlay'), 200);
        await AnimationSystem.slideIn(DOM.projectModal.querySelector('.modal-content'), 'bottom', 300);
        
        this.setupModalEvents();
        this.setupImageGallery();
    }
    
    createModalContent(project) {
        return `
            <div class="modal-header">
                <h3 class="modal-title">${project.title}</h3>
                <span class="modal-category">${project.categoryLabel}</span>
            </div>
            
            <div class="modal-gallery">
                <div class="gallery-main">
                    <img src="${project.images[0]}" alt="${project.title}" class="gallery-image active">
                </div>
                <div class="gallery-thumbs">
                    ${project.images.map((img, index) => `
                        <img src="${img}" alt="${project.title} - تصویر ${index + 1}" 
                             class="gallery-thumb ${index === 0 ? 'active' : ''}"
                             data-index="${index}">
                    `).join('')}
                </div>
            </div>
            
            <div class="modal-details">
                <div class="detail-section">
                    <h4 class="detail-title">توضیحات پروژه</h4>
                    <p class="detail-text">${project.fullDescription}</p>
                </div>
                
                <div class="detail-grid">
                    <div class="detail-item">
                        <h5 class="detail-label">تکنولوژی‌ها</h5>
                        <div class="tech-tags">
                            ${project.technologies.map(tech => `
                                <span class="tech-tag">${tech}</span>
                            `).join('')}
                        </div>
                    </div>
                    
                    <div class="detail-item">
                        <h5 class="detail-label">مدت زمان</h5>
                        <p class="detail-value">${project.duration}</p>
                    </div>
                    
                    <div class="detail-item">
                        <h5 class="detail-label">خدمات ارائه شده</h5>
                        <ul class="services-list">
                            ${project.services.map(service => `
                                <li>${service}</li>
                            `).join('')}
                        </ul>
                    </div>
                </div>
                
                <div class="modal-actions">
                    ${project.demoUrl ? `
                        <a href="${project.demoUrl}" class="btn btn-primary" target="_blank" rel="noopener">
                            مشاهده دمو
                        </a>
                    ` : ''}
                    <button class="btn btn-outline request-similar" data-project="${project.id}">
                        درخواست پروژه مشابه
                    </button>
                </div>
            </div>
        `;
    }
    
    setupModalEvents() {
        const closeModal = () => {
            state.isModalOpen = false;
            DOM.body.style.overflow = '';
            
            AnimationSystem.fadeOut(DOM.projectModal.querySelector('.modal-overlay'), 200);
            AnimationSystem.slideOut(DOM.projectModal.querySelector('.modal-content'), 'bottom', 300).then(() => {
                DOM.projectModal.classList.remove('active');
            });
        };
        
        DOM.modalClose.addEventListener('click', closeModal);
        DOM.projectModal.querySelector('.modal-overlay').addEventListener('click', closeModal);
        
        const requestButtons = document.querySelectorAll('.request-similar');
        requestButtons.forEach(button => {
            button.addEventListener('click', () => {
                closeModal();
                setTimeout(() => {
                    DOM.sections.contact.scrollIntoView({ behavior: 'smooth' });
                    DOM.formFields.projectType.value = PROJECTS[button.dataset.project].category;
                }, 400);
            });
        });
        
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && state.isModalOpen) {
                closeModal();
            }
        });
    }
    
    setupImageGallery() {
        const thumbs = document.querySelectorAll('.gallery-thumb');
        const mainImage = document.querySelector('.gallery-image');
        
        thumbs.forEach(thumb => {
            thumb.addEventListener('click', () => {
                thumbs.forEach(t => t.classList.remove('active'));
                thumb.classList.add('active');
                
                mainImage.classList.remove('active');
                setTimeout(() => {
                    mainImage.src = thumb.src;
                    mainImage.alt = thumb.alt;
                    mainImage.classList.add('active');
                }, 200);
            });
        });
    }
}

// 📧 Contact System
class ContactSystem {
    constructor() {
        this.form = DOM.contactForm;
        this.fields = DOM.formFields;
        this.errors = DOM.formErrors;
        this.submitBtn = DOM.submitBtn;
        this.submitLoader = DOM.submitLoader;
        this.copyButtons = DOM.copyButtons;
        this.telegramBtn = DOM.telegramBtn;
    }
    
    init() {
        this.setupFormValidation();
        this.setupFormSubmission();
        this.setupCopyButtons();
        this.setupTelegramButton();
    }
    
    setupFormValidation() {
        Object.keys(this.fields).forEach(fieldName => {
            const field = this.fields[fieldName];
            const errorElement = this.errors[fieldName];
            
            field.addEventListener('input', () => {
                this.validateField(fieldName);
                state.formData[fieldName] = field.value;
            });
            
            field.addEventListener('blur', () => {
                this.validateField(fieldName);
            });
            
            field.addEventListener('focus', () => {
                errorElement.classList.remove('show');
                errorElement.textContent = '';
            });
        });
    }
    
    validateField(fieldName) {
        const field = this.fields[fieldName];
        const errorElement = this.errors[fieldName];
        let isValid = true;
        let errorMessage = '';
        
        switch (fieldName) {
            case 'name':
                if (!field.value.trim()) {
                    isValid = false;
                    errorMessage = 'نام و نام خانوادگی الزامی است';
                } else if (field.value.trim().length < 3) {
                    isValid = false;
                    errorMessage = 'نام باید حداقل ۳ حرف داشته باشد';
                }
                break;
                
            case 'email':
                if (!field.value.trim()) {
                    isValid = false;
                    errorMessage = 'آدرس ایمیل الزامی است';
                } else if (!this.isValidEmail(field.value)) {
                    isValid = false;
                    errorMessage = 'آدرس ایمیل معتبر نیست';
                }
                break;
                
            case 'projectType':
                if (!field.value) {
                    isValid = false;
                    errorMessage = 'لطفاً موضوع پروژه را انتخاب کنید';
                }
                break;
                
            case 'message':
                if (!field.value.trim()) {
                    isValid = false;
                    errorMessage = 'پیام شما الزامی است';
                } else if (field.value.trim().length < 20) {
                    isValid = false;
                    errorMessage = 'پیام باید حداقل ۲۰ کاراکتر باشد';
                }
                break;
        }
        
        if (!isValid) {
            errorElement.textContent = errorMessage;
            errorElement.classList.add('show');
            field.classList.add('error');
        } else {
            errorElement.classList.remove('show');
            errorElement.textContent = '';
            field.classList.remove('error');
        }
        
        return isValid;
    }
    
    isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
    
    validateForm() {
        let isValid = true;
        
        Object.keys(this.fields).forEach(fieldName => {
            if (!this.validateField(fieldName)) {
                isValid = false;
            }
        });
        
        return isValid;
    }
    
    async setupFormSubmission() {
        this.form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            if (state.isFormSubmitting) return;
            
            if (!this.validateForm()) {
                this.showToast('لطفاً تمام فیلدها را به درستی پر کنید', 'error');
                return;
            }
            
            state.isFormSubmitting = true;
            this.showLoadingState(true);
            
            try {
                await this.submitForm();
                this.showToast('پیام شما با موفقیت ارسال شد! به زودی با شما تماس می‌گیریم.', 'success');
                this.form.reset();
                state.resetForm();
            } catch (error) {
                console.error('Form submission error:', error);
                this.showToast('خطا در ارسال پیام. لطفاً دوباره تلاش کنید.', 'error');
            } finally {
                state.isFormSubmitting = false;
                this.showLoadingState(false);
            }
        });
    }
    
    async submitForm() {
        return new Promise((resolve, reject) => {
            setTimeout(() => {
                resolve();
            }, 1500);
            
        });
    }
    
    showLoadingState(show) {
        if (show) {
            this.submitBtn.disabled = true;
            this.submitBtn.querySelector('.btn-text').style.opacity = '0';
            this.submitLoader.style.display = 'block';
        } else {
            this.submitBtn.disabled = false;
            this.submitBtn.querySelector('.btn-text').style.opacity = '1';
            this.submitLoader.style.display = 'none';
        }
    }
    
    setupCopyButtons() {
        this.copyButtons.forEach(button => {
            button.addEventListener('click', () => {
                const text = button.dataset.text;
                const type = button.dataset.type;
                this.copyToClipboard(text, type);
            });
        });
    }
    
    async copyToClipboard(text, type) {
        try {
            await navigator.clipboard.writeText(text);
            this.showToast(`${type} با موفقیت کپی شد`, 'success');
        } catch (err) {
            console.error('Clipboard copy failed:', err);
            
            const textArea = document.createElement('textarea');
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.select();
            
            try {
                document.execCommand('copy');
                this.showToast(`${type} با موفقیت کپی شد`, 'success');
            } catch (err2) {
                console.error('Fallback copy failed:', err2);
                this.showToast('خطا در کپی کردن', 'error');
            }
            
            document.body.removeChild(textArea);
        }
    }
    
    setupTelegramButton() {
        this.telegramBtn.addEventListener('click', (e) => {
            e.preventDefault();
            this.openTelegram();
        });
    }
    
    openTelegram() {
        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        const startTime = Date.now();
        
        if (isMobile) {
            window.location.href = CONFIG.telegramAppLink;
            
            setTimeout(() => {
                const elapsedTime = Date.now() - startTime;
                if (elapsedTime < 2000) {
                    window.open(CONFIG.telegramLink, '_blank');
                }
            }, 1500);
        } else {
            window.open(CONFIG.telegramLink, '_blank');
        }
    }
    
    showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <span class="toast-icon">${type === 'success' ? '✓' : '⚠️'}</span>
            <span class="toast-message">${message}</span>
        `;
        
        DOM.toastContainer.appendChild(toast);
        
        setTimeout(() => {
            toast.classList.add('show');
        }, 10);
        
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, CONFIG.toastDuration);
    }
}

// 📈 Stats Counter System
class StatsCounterSystem {
    constructor() {
        this.counters = DOM.statCounters;
        this.observer = null;
    }
    
    init() {
        this.setupIntersectionObserver();
    }
    
    setupIntersectionObserver() {
        this.observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    this.startCounters(entry.target);
                    this.observer.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.5,
            rootMargin: '0px 0px -100px 0px'
        });
        
        DOM.statCards.forEach(card => {
            this.observer.observe(card);
        });
    }
    
    async startCounters(card) {
        const countElement = card.querySelector('.count');
        const target = parseInt(card.dataset.count);
        const speed = parseInt(card.dataset.speed);
        
        if (!countElement || isNaN(target)) return;
        
        await AnimationSystem.createCounterAnimation(countElement, target, speed);
    }
}

// 🏃‍♂️ Scroll System
class ScrollSystem {
    constructor() {
        this.backToTop = DOM.backToTop;
        this.navbar = DOM.navbar;
        this.navLinks = DOM.navLinks;
        this.sections = DOM.sections;
        this.scrollPosition = 0;
        this.isScrolling = false;
        this.scrollTimeout = null;
    }
    
    init() {
        this.setupScrollEvents();
        this.setupBackToTop();
        this.setupNavLinks();
        this.setupScrollAnimations();
    }
    
    setupScrollEvents() {
        let lastScrollTop = 0;
        
        window.addEventListener('scroll', () => {
            this.scrollPosition = window.scrollY;
            
            this.handleNavbarScroll();
            this.handleBackToTop();
            this.handleSectionActivation();
            
            this.isScrolling = true;
            
            clearTimeout(this.scrollTimeout);
            this.scrollTimeout = setTimeout(() => {
                this.isScrolling = false;
            }, 100);
            
            lastScrollTop = this.scrollPosition <= 0 ? 0 : this.scrollPosition;
        }, { passive: true });
    }
    
    handleNavbarScroll() {
        if (this.scrollPosition > CONFIG.scrollThreshold) {
            this.navbar.classList.add('scrolled');
        } else {
            this.navbar.classList.remove('scrolled');
        }
    }
    
    handleBackToTop() {
        if (this.scrollPosition > 500) {
            this.backToTop.classList.add('visible');
        } else {
            this.backToTop.classList.remove('visible');
        }
    }
    
    handleSectionActivation() {
        const scrollPosition = window.scrollY + 100;
        
        Object.values(this.sections).forEach(section => {
            if (!section) return;
            
            const sectionTop = section.offsetTop;
            const sectionHeight = section.offsetHeight;
            
            if (scrollPosition >= sectionTop && scrollPosition < sectionTop + sectionHeight) {
                const sectionId = section.id;
                this.updateActiveNavLink(sectionId);
            }
        });
    }
    
    updateActiveNavLink(sectionId) {
        this.navLinks.forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('href') === `#${sectionId}`) {
                link.classList.add('active');
            }
        });
    }
    
    setupBackToTop() {
        this.backToTop.addEventListener('click', () => {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }
    
    setupNavLinks() {
        this.navLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                
                const targetId = link.getAttribute('href');
                if (!targetId || targetId === '#') return;
                
                const targetElement = document.querySelector(targetId);
                if (!targetElement) return;
                
                if (state.isMobileMenuOpen) {
                    this.closeMobileMenu();
                }
                
                const navbarHeight = this.navbar.offsetHeight;
                const targetPosition = targetElement.offsetTop - navbarHeight;
                
                window.scrollTo({
                    top: targetPosition,
                    behavior: 'smooth'
                });
                
                this.updateActiveNavLink(targetId.substring(1));
            });
        });
    }
    
    setupScrollAnimations() {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-in');
                    
                    if (entry.target.classList.contains('service-card')) {
                        entry.target.style.animationDelay = `${Array.from(DOM.serviceCards).indexOf(entry.target) * 100}ms`;
                    }
                    
                    if (entry.target.classList.contains('team-card')) {
                        entry.target.style.animationDelay = `${Array.from(DOM.teamCards).indexOf(entry.target) * 150}ms`;
                    }
                }
            });
        }, {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        });
        
        document.querySelectorAll('.service-card, .team-card, .stat-card, .portfolio-card').forEach(el => {
            observer.observe(el);
        });
    }
    
    closeMobileMenu() {
        if (state.isMobileMenuOpen) {
            state.isMobileMenuOpen = false;
            DOM.hamburger.classList.remove('active');
            AnimationSystem.slideOut(DOM.navMenu, 'right', 300).then(() => {
                DOM.navMenu.classList.remove('active');
                DOM.body.style.overflow = '';
            });
        }
    }
}

// 📱 Mobile Menu System
class MobileMenuSystem {
    constructor() {
        this.hamburger = DOM.hamburger;
        this.navMenu = DOM.navMenu;
        this.navLinks = DOM.navLinks;
    }
    
    init() {
        this.setupHamburger();
        this.setupMenuLinks();
        this.setupOutsideClick();
    }
    
    setupHamburger() {
        this.hamburger.addEventListener('click', () => {
            this.toggleMobileMenu();
        });
    }
    
    async toggleMobileMenu() {
        if (!state.isMobileMenuOpen) {
            state.isMobileMenuOpen = true;
            DOM.body.style.overflow = 'hidden';
            DOM.navMenu.classList.add('active');
            this.hamburger.classList.add('active');
            
            await AnimationSystem.slideIn(this.navMenu, 'right', 300);
            
            const menuItems = this.navMenu.querySelectorAll('.nav-item');
            await AnimationSystem.staggerChildren(this.navMenu, (item) => {
                return AnimationSystem.fadeIn(item, 200);
            }, 100);
        } else {
            await this.closeMobileMenu();
        }
    }
    
    async closeMobileMenu() {
        state.isMobileMenuOpen = false;
        this.hamburger.classList.remove('active');
        
        await AnimationSystem.slideOut(this.navMenu, 'right', 300);
        
        DOM.navMenu.classList.remove('active');
        DOM.body.style.overflow = '';
    }
    
    setupMenuLinks() {
        this.navLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (state.isMobileMenuOpen) {
                    this.closeMobileMenu();
                }
            });
        });
    }
    
    setupOutsideClick() {
        document.addEventListener('click', (e) => {
            if (state.isMobileMenuOpen && 
                !this.navMenu.contains(e.target) && 
                !this.hamburger.contains(e.target)) {
                this.closeMobileMenu();
            }
        });
    }
}

// 🎪 Marquee System
class MarqueeSystem {
    constructor() {
        this.track = DOM.marqueeTrack;
        this.items = [];
        this.isPaused = false;
        this.animationSpeed = 40;
    }
    
    init() {
        if (!this.track) return;
        
        this.setupItems();
        this.setupHoverEffect();
        this.startAnimation();
    }
    
    setupItems() {
        const items = this.track.children;
        this.items = Array.from(items);
        
        this.items.forEach(item => {
            const clone = item.cloneNode(true);
            this.track.appendChild(clone);
        });
    }
    
    setupHoverEffect() {
        this.items.forEach(item => {
            item.addEventListener('mouseenter', () => {
                this.isPaused = true;
                this.track.style.animationPlayState = 'paused';
            });
            
            item.addEventListener('mouseleave', () => {
                this.isPaused = false;
                this.track.style.animationPlayState = 'running';
            });
        });
    }
    
    startAnimation() {
        this.track.style.animation = `marquee ${this.animationSpeed}s linear infinite`;
    }
}

// 🚀 Application Core
class MRNeonApp {
    constructor() {
        this.systems = {
            scroll: new ScrollSystem(),
            mobileMenu: new MobileMenuSystem(),
            portfolio: new PortfolioSystem(),
            contact: new ContactSystem(),
            stats: new StatsCounterSystem(),
            marquee: new MarqueeSystem(),
            particle: null
        };
    }
    
    async init() {
        try {
            this.setupCSSVariables();
            await this.preloadAssets();
            await this.initializeSystems();
            await this.setupEventListeners();
            await this.animateOnLoad();
            
            console.log('🚀 MR NEON App Initialized Successfully');
        } catch (error) {
            console.error('App initialization failed:', error);
        }
    }
    
    setupCSSVariables() {
        const root = document.documentElement;
        
        const variables = {
            '--primary': '#0FA3B1',
            '--secondary': '#2ECC71',
            '--neon': '#00F5FF',
            '--success': '#27AE60',
            '--error': '#E74C3C',
            '--text-primary': '#1A1A1A',
            '--text-secondary': '#6C757D',
            '--bg-primary': '#FFFFFF',
            '--bg-secondary': '#F8F9FA',
            '--border-color': '#E0E0E0',
            '--shadow-md': '0 5px 15px rgba(0, 0, 0, 0.05)',
            '--font-vazir': 'Vazir, system-ui, sans-serif',
            '--radius-sm': '4px',
            '--radius-md': '8px',
            '--radius-lg': '12px',
            '--radius-xl': '16px',
            '--space-xs': '8px',
            '--space-sm': '16px',
            '--space-md': '24px',
            '--space-lg': '32px',
            '--space-xl': '48px',
            '--space-xxl': '64px'
        };
        
        Object.entries(variables).forEach(([key, value]) => {
            root.style.setProperty(key, value);
        });
    }
    
    async preloadAssets() {
        return new Promise((resolve) => {
            setTimeout(() => {
                resolve();
            }, 500);
        });
    }
    
    async initializeSystems() {
        this.systems.scroll.init();
        this.systems.mobileMenu.init();
        this.systems.portfolio.init();
        this.systems.contact.init();
        this.systems.stats.init();
        this.systems.marquee.init();
        
        this.systems.particle = new ParticleSystem(DOM.particlesContainer);
        this.systems.particle.init();
    }
    
    async setupEventListeners() {
        window.addEventListener('resize', this.handleResize.bind(this));
        window.addEventListener('orientationchange', this.handleOrientationChange.bind(this));
        window.addEventListener('beforeunload', this.handleBeforeUnload.bind(this));
        
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.systems.particle.stop();
            } else {
                this.systems.particle.start();
            }
        });
    }
    
    handleResize() {
        clearTimeout(this.resizeTimeout);
        this.resizeTimeout = setTimeout(() => {
            if (window.innerWidth > 992 && state.isMobileMenuOpen) {
                this.systems.mobileMenu.closeMobileMenu();
            }
            
            this.systems.particle.destroy();
            this.systems.particle = new ParticleSystem(DOM.particlesContainer);
            this.systems.particle.init();
        }, 250);
    }
    
    handleOrientationChange() {
        setTimeout(() => {
            this.handleResize();
        }, 100);
    }
    
    handleBeforeUnload(e) {
        if (state.isFormSubmitting) {
            e.preventDefault();
            e.returnValue = 'در حال ارسال فرم... لطفاً صبر کنید.';
        }
    }
    
    async animateOnLoad() {
        await AnimationSystem.fadeOut(DOM.loadingScreen, 500, 1000);
        DOM.loadingScreen.classList.add('hidden');
        
        await AnimationSystem.staggerChildren(DOM.hero.querySelector('.hero-features'), (item) => {
            return AnimationSystem.fadeIn(item, 300);
        }, 100);
        
        await AnimationSystem.fadeIn(DOM.hero.querySelector('.hero-actions'), 500);
    }
    
    destroy() {
        this.systems.particle.destroy();
        
        window.removeEventListener('resize', this.handleResize);
        window.removeEventListener('orientationchange', this.handleOrientationChange);
        window.removeEventListener('beforeunload', this.handleBeforeUnload);
        
        Object.values(this.systems).forEach(system => {
            if (system && typeof system.destroy === 'function') {
                system.destroy();
            }
        });
    }
}

// 🚀 Initialize Application
document.addEventListener('DOMContentLoaded', () => {
    const app = new MRNeonApp();
    app.init();
    
    window.MRNeonApp = app;
});

// 🎯 Utility Functions
const Utils = {
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },
    
    throttle(func, limit) {
        let inThrottle;
        return function() {
            const args = arguments;
            const context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    },
    
    formatNumber(num) {
        return new Intl.NumberFormat('fa-IR').format(num);
    },
    
    getScrollPercentage() {
        const h = document.documentElement;
        const b = document.body;
        const st = 'scrollTop';
        const sh = 'scrollHeight';
        return (h[st] || b[st]) / ((h[sh] || b[sh]) - h.clientHeight) * 100;
    },
    
    isInViewport(element, offset = 0) {
        const rect = element.getBoundingClientRect();
        return (
            rect.top >= 0 &&
            rect.left >= 0 &&
            rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) + offset &&
            rect.right <= (window.innerWidth || document.documentElement.clientWidth)
        );
    },
    
    generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c == 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    },
    
    sanitizeHTML(str) {
        const temp = document.createElement('div');
        temp.textContent = str;
        return temp.innerHTML;
    }
};

// 📦 Export for debugging
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        MRNeonApp,
        Utils,
        AnimationSystem,
        ParticleSystem
    };
}
// Floating Contact Widget
document.addEventListener('DOMContentLoaded', function() {
    const floatingContact = document.getElementById('floatingContact');
    const floatingContactBtn = document.getElementById('floatingContactBtn');
    const contactPanel = document.getElementById('contactPanel');
    const copyButtons = document.querySelectorAll('.contact-panel-copy');
    const quickCallBtn = document.getElementById('quickCallBtn');
    
    // Toggle contact panel
    floatingContactBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        floatingContact.classList.toggle('active');
    });
    
    // Close panel when clicking outside
    document.addEventListener('click', function(e) {
        if (!floatingContact.contains(e.target)) {
            floatingContact.classList.remove('active');
        }
    });
    
    // Copy to clipboard functionality
    copyButtons.forEach(button => {
        button.addEventListener('click', function() {
            const textToCopy = this.getAttribute('data-text');
            
            navigator.clipboard.writeText(textToCopy).then(() => {
                // Show success notification
                showToast(`"${textToCopy}" کپی شد`);
                
                // Add visual feedback
                const originalHTML = this.innerHTML;
                this.innerHTML = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M13.3333 6H7.33333C6.59695 6 6 6.59695 6 7.33333V13.3333C6 14.0697 6.59695 14.6667 7.33333 14.6667H13.3333C14.0697 14.6667 14.6667 14.0697 14.6667 13.3333V7.33333C14.6667 6.59695 14.0697 6 13.3333 6Z" stroke="#00f5ff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.33333 10H2.66667C2.29848 10 2 9.70152 2 9.33333V2.66667C2 2.29848 2.29848 2 2.66667 2H9.33333C9.70152 2 10 2.29848 10 2.66667V3.33333" stroke="#00f5ff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                this.style.color = '#00f5ff';
                
                setTimeout(() => {
                    this.innerHTML = originalHTML;
                    this.style.color = '';
                }, 2000);
            }).catch(err => {
                console.error('خطا در کپی کردن: ', err);
                showToast('خطا در کپی کردن', 'error');
            });
        });
    });
    
    // Quick call button functionality
    quickCallBtn.addEventListener('click', function() {
        // In a real implementation, this would open a form or initiate a call
        showToast('به زودی با شما تماس می‌گیریم!', 'success');
        
        // Close the panel
        floatingContact.classList.remove('active');
        
        // Scroll to contact form
        document.getElementById('contact').scrollIntoView({ behavior: 'smooth' });
        
        // Focus on name field in contact form
        setTimeout(() => {
            const nameField = document.getElementById('name');
            if (nameField) {
                nameField.focus();
            }
        }, 500);
    });
    
    // Toast notification function
    function showToast(message, type = 'success') {
        let toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toastContainer';
            toastContainer.className = 'toast-container';
            document.body.appendChild(toastContainer);
        }
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        
        toastContainer.appendChild(toast);
        
        setTimeout(() => {
            toast.classList.add('fade-out');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 3000);
    }
    
    // Close panel with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            floatingContact.classList.remove('active');
        }
    });
    
    // Prevent panel from closing when clicking inside it
    contactPanel.addEventListener('click', function(e) {
        e.stopPropagation();
    });
    
    // Close panel when scrolling on mobile
    let touchStartY = 0;
    let touchEndY = 0;
    
    document.addEventListener('touchstart', function(e) {
        touchStartY = e.changedTouches[0].screenY;
    }, false);
    
    document.addEventListener('touchend', function(e) {
        touchEndY = e.changedTouches[0].screenY;
        // اگر اسکرول به اندازه قابل توجهی انجام شد، پنل را ببند
        if (Math.abs(touchEndY - touchStartY) > 50 && floatingContact.classList.contains('active')) {
            floatingContact.classList.remove('active');
        }
    }, false);
}); 
