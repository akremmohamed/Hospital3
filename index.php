<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Required Meta Tags for responsiveness & performance -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    <!-- Page Title -->
    <title>HMS | Intelligent Hospital Management System</title>

    <!-- Favicon (placeholder using emoji but with style) -->
    <link rel="shortcut icon" href="#" type="image/x-icon">
    
    <!-- Google Fonts + Modern Icons (Font Awesome 6) -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- Complete CSS reset & modern responsive system -->
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f4f9fc;
            color: #1a2c3e;
            overflow-x: hidden;
            scroll-behavior: smooth;
        }

        /* animated gradient background */
        .bg-soft {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
            background: linear-gradient(125deg, #e9f2f5 0%, #dceaf1 100%);
        }
        
        .floating-shape {
            position: fixed;
            width: 60vw;
            height: 60vw;
            background: radial-gradient(circle, rgba(46, 125, 170, 0.08) 0%, rgba(27, 94, 133, 0.02) 80%);
            border-radius: 45% 55% 70% 30% / 50% 40% 60% 50%;
            filter: blur(70px);
            z-index: -1;
            animation: drift 30s infinite alternate ease-in-out;
        }
        
        .shape2 {
            width: 45vw;
            height: 45vw;
            bottom: -15vh;
            right: -10vw;
            background: radial-gradient(circle, rgba(70, 130, 180, 0.06) 0%, rgba(41, 98, 145, 0.02) 90%);
            animation-duration: 22s;
            animation-direction: alternate-reverse;
        }
        
        @keyframes drift {
            0% { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(6%, 8%) rotate(8deg); }
        }

        /* responsive container */
        .container {
            width: 100%;
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 28px;
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 20px;
            }
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 0 16px;
            }
        }

        /* Glassmorphism header */
        .header-area {
            position: sticky;
            top: 0;
            z-index: 100;
            padding: 18px 0;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(255,255,255,0.6);
            transition: all 0.3s ease;
        }
        
        .nav-flex {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .logo a {
            font-size: 1.8rem;
            font-weight: 800;
            background: linear-gradient(135deg, #155799, #159957);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: -0.3px;
        }
        
        .logo i {
            background: none;
            color: #1f6e8c;
            font-size: 1.9rem;
        }
        
        .nav-menu {
            display: flex;
            gap: 28px;
            list-style: none;
            flex-wrap: wrap;
        }
        
        .nav-menu li a {
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            color: #1f3a4b;
            padding: 8px 12px;
            border-radius: 40px;
            transition: 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .nav-menu li a i {
            font-size: 1rem;
        }
        
        .nav-menu li a:hover {
            background: rgba(31, 110, 140, 0.12);
            color: #0f5b7a;
            transform: translateY(-2px);
        }
        
        .menu-active a {
            background: rgba(31, 110, 140, 0.18);
            color: #0c4e6e;
            font-weight: 700;
        }
        
        @media (max-width: 780px) {
            .nav-flex {
                flex-direction: column;
                align-items: center;
            }
            .nav-menu {
                justify-content: center;
                gap: 18px;
            }
            .logo a {
                font-size: 1.6rem;
            }
        }
        
        @media (max-width: 550px) {
            .nav-menu li a {
                padding: 6px 10px;
                font-size: 0.85rem;
            }
        }
        
        /* Hero Section - fully responsive */
        .hero-section {
            padding: 70px 0 80px;
        }
        
        .hero-grid {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 48px;
        }
        
        .hero-content {
            flex: 1.2;
            min-width: 260px;
        }
        
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(31, 110, 140, 0.12);
            padding: 6px 18px;
            border-radius: 60px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #155e7b;
            margin-bottom: 24px;
            backdrop-filter: blur(2px);
        }
        
        .hero-content h4 {
            font-size: 1.2rem;
            font-weight: 500;
            color: #1f6e8c;
            letter-spacing: -0.2px;
            margin-bottom: 12px;
        }
        
        .hero-content h1 {
            font-size: clamp(2.2rem, 6vw, 4rem);
            font-weight: 800;
            line-height: 1.2;
            background: linear-gradient(125deg, #144d66, #1f7a5a, #2b8c6e);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 24px;
        }
        
        .hero-description {
            font-size: 1rem;
            line-height: 1.5;
            color: #2c4c66;
            margin-bottom: 36px;
            max-width: 90%;
        }
        
        @media (max-width: 900px) {
            .hero-description {
                max-width: 100%;
            }
        }
        
        .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 28px;
            border-radius: 60px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.25s;
            text-decoration: none;
            border: none;
            cursor: pointer;
        }
        
        .btn-primary {
            background: #1f6e8c;
            color: white;
            box-shadow: 0 8px 18px rgba(31,110,140,0.25);
        }
        
        .btn-primary:hover {
            background: #0e5975;
            transform: translateY(-3px);
            box-shadow: 0 12px 22px rgba(31,110,140,0.3);
        }
        
        .btn-outline {
            background: rgba(255,255,255,0.7);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(31,110,140,0.35);
            color: #1f6e8c;
        }
        
        .btn-outline:hover {
            background: white;
            border-color: #1f6e8c;
            transform: translateY(-2px);
        }
        
        /* Stats Card */
        .hero-visual {
            flex: 1;
            min-width: 260px;
        }
        
        .stats-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(14px);
            border-radius: 40px;
            padding: 28px 24px;
            border: 1px solid rgba(255,255,255,0.7);
            box-shadow: 0 20px 35px -12px rgba(0,0,0,0.08);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 28px;
            margin-bottom: 28px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: #155e7b;
        }
        
        .stat-label {
            font-size: 0.8rem;
            font-weight: 500;
            color: #3a6b85;
        }
        
        .feature-bullets {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-top: 12px;
        }
        
        .bullet {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.85rem;
            font-weight: 500;
            color: #1d4d66;
        }
        
        .bullet i {
            color: #1f8a6b;
            width: 24px;
        }
        
        @media (max-width: 680px) {
            .hero-grid {
                flex-direction: column;
                gap: 40px;
            }
            .stats-grid {
                gap: 20px;
            }
            .btn {
                padding: 10px 22px;
                font-size: 0.9rem;
            }
        }
        
        /* Features Grid (responsive) */
        .features-section {
            margin: 50px 0 60px;
        }
        
        .section-title {
            text-align: center;
            font-size: clamp(1.8rem, 5vw, 2.6rem);
            font-weight: 700;
            margin-bottom: 48px;
            color: #143e52;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
        }
        
        .feature-card {
            background: rgba(255, 255, 255, 0.65);
            backdrop-filter: blur(8px);
            border-radius: 32px;
            padding: 30px 24px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.7);
            box-shadow: 0 8px 20px rgba(0,0,0,0.02);
        }
        
        .feature-card:hover {
            transform: translateY(-8px);
            background: rgba(255,255,255,0.85);
            border-color: rgba(31,110,140,0.25);
            box-shadow: 0 22px 30px -12px rgba(0,0,0,0.1);
        }
        
        .feature-icon {
            font-size: 2.6rem;
            background: linear-gradient(135deg, #1f6e8c, #23967a);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 20px;
        }
        
        .feature-card h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 12px;
        }
        
        .feature-card p {
            color: #3b5c74;
            line-height: 1.45;
        }
        
        /* CTA Banner (fully responsive) */
        .cta-banner {
            background: linear-gradient(105deg, #0c3f52, #1b6a58);
            border-radius: 48px;
            padding: 48px 40px;
            margin: 50px 0 70px;
            color: white;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 30px;
            box-shadow: 0 28px 40px -18px rgba(0,0,0,0.25);
        }
        
        .cta-text h2 {
            font-size: clamp(1.5rem, 5vw, 2.2rem);
            font-weight: 700;
            margin-bottom: 12px;
        }
        
        .cta-text p {
            opacity: 0.9;
        }
        
        .btn-light {
            background: white;
            color: #155e7b;
            padding: 14px 32px;
            border-radius: 60px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            transition: 0.2s;
            box-shadow: 0 8px 16px rgba(0,0,0,0.08);
        }
        
        .btn-light:hover {
            transform: scale(1.02);
            background: #f0fafc;
        }
        
        @media (max-width: 700px) {
            .cta-banner {
                flex-direction: column;
                text-align: center;
                padding: 40px 28px;
            }
            .btn-light {
                padding: 12px 28px;
            }
        }
        
        /* footer */
        .footer {
            text-align: center;
            padding: 36px 0 28px;
            border-top: 1px solid rgba(0,0,0,0.05);
            color: #4f6f87;
            font-size: 0.9rem;
        }
        
        /* Preloader */
        .preloader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: #eef5fa;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: opacity 0.5s;
        }
        
        .loader-ring {
            width: 48px;
            height: 48px;
            border: 4px solid rgba(31,110,140,0.2);
            border-top: 4px solid #1f6e8c;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }
        
        @keyframes spin { to { transform: rotate(360deg); } }
        
        /* utility */
        .text-center {
            text-align: center;
        }
    </style>
</head>
<body>

<div class="bg-soft"></div>
<div class="floating-shape"></div>
<div class="floating-shape shape2"></div>

<!-- Preloader -->
<div class="preloader" id="preloader">
    <div class="loader-ring"></div>
</div>

<!-- Header Area (sticky & glass) -->
<header class="header-area">
    <div class="container">
        <div class="nav-flex">
            <div class="logo">
                <a href="index.php"><i class="fas fa-hospital-user"></i> Meki Hospital</a>
            </div>
            <nav>
                <ul class="nav-menu">
                    <li class="menu-active"><a href="index.php"><i class="fas fa-home"></i> Home</a></li>
                    <li><a href="login.php" ><i class="fas fa-lock"></i> super Administrator Login</a></li> 
                    <li><a href="backend/branck_admin/index.php"><i class="fas fa-building"></i> Branch Administrator Login</a></li>     
                    <li><a href="backend/acc/index.php"><i class="fas fa-money-bill-wave"></i> Accountant Login</a></li>             
                    <li><a href="backend/doc/index.php"><i class="fas fa-user-md"></i> Doctor Login</a></li>
                    <li><a href="backend/lab/index.php"><i class="fas fa-vial"></i> Laboratory Login</a></li>
                    <li><a href="backend/nurs/index.php" ><i class="fas fa-user-nurse"></i> Nurse Login</a></li>
                    <li><a href="backend/pharm/index.php" ><i class="fas fa-pills"></i> Pharmacist Login</a></li>
                    <li><a href="backend/receptions/index.php" ><i class="fas fa-user"></i> Receptionist Login</a></li>
                </ul>
            </nav>
        </div>
    </div>
</header>

<!-- Hero Banner -->
<section class="hero-section">
    <div class="container">
        <div class="hero-grid">
            <div class="hero-content">
                <div class="hero-badge">
                    <i class="fas fa-trophy"></i> 2026 Excellence Award
                </div>
                <h4>Caring for better life</h4>
                <h1>Leading the way in medical excellence</h1>
                <p class="hero-description">
                    HMS is awarded as one of the Top Hospital Management Systems, integrating all HIS systems, processes, and machines into an intelligent information system to derive operational efficiency and assist hospitals in decision-making through MIS & Analytics.
                </p>
                <div class="btn-group">
                    <a href="#" class="btn btn-primary demo-btn"><i class="fas fa-calendar-alt"></i> Schedule Demo</a>
                    <a href="#" class="btn btn-outline analytics-btn"><i class="fas fa-chart-line"></i> Explore Analytics</a>
                </div>
            </div>
            <div class="hero-visual">
                <div class="stats-card">
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-number">98%</div>
                            <div class="stat-label">Patient Satisfaction</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">24/7</div>
                            <div class="stat-label">AI Support</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">450+</div>
                            <div class="stat-label">Hospitals</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">2.5M+</div>
                            <div class="stat-label">Yearly Patients</div>
                        </div>
                    </div>
                    <div class="feature-bullets">
                        <div class="bullet"><i class="fas fa-microchip"></i> AI-powered clinical workflows</div>
                        <div class="bullet"><i class="fas fa-chart-pie"></i> Real-time MIS dashboards</div>
                        <div class="bullet"><i class="fas fa-shield-alt"></i> HIPAA & GDPR compliant</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Features Grid (modern responsive) -->
<div class="container features-section">
    <h2 class="section-title">Intelligent Ecosystem for Modern Healthcare</h2>
    <div class="features-grid">
        <div class="feature-card">
            <div class="feature-icon"><i class="fas fa-robot"></i></div>
            <h3>Smart Automation</h3>
            <p>Reduce manual tasks with robotic process automation, integrated HIS modules, and smart scheduling.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fas fa-chalkboard-user"></i></div>
            <h3>Analytics Hub</h3>
            <p>Interactive MIS dashboards & predictive analytics to empower hospital leadership decisions.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fas fa-cloud-upload-alt"></i></div>
            <h3>Cloud-First</h3>
            <p>Seamless scalability with hybrid cloud, ensuring data security and real-time backups.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fas fa-head-side-medical"></i></div>
            <h3>Telemedicine</h3>
            <p>Integrated virtual care, e-prescriptions, and remote monitoring for extended patient reach.</p>
        </div>
    </div>
</div>

<!-- CTA Banner -->
<div class="container">
    <div class="cta-banner">
        <div class="cta-text">
            <h2>Ready to transform your hospital?</h2>
            <p>Join 450+ healthcare institutions leveraging HMS for operational excellence.</p>
        </div>
        <div>
            <a href="#" class="btn-light cta-trigger"><i class="fas fa-arrow-right"></i> Get started now</a>
        </div>
    </div>
</div>

<!-- Footer -->
<footer class="footer">
    <div class="container">
        <p><i class="far fa-copyright"></i> <span id="currentYear"></span> HMS - Next Generation Hospital Management | Intelligent · Secure · Scalable</p>
        <p style="margin-top: 14px; font-size:0.8rem;"><i class="fas fa-heart" style="color:#c44536;"></i> Empowering healthcare professionals worldwide</p>
    </div>
</footer>

<script>
    // Preloader removal with smooth fade
    window.addEventListener('load', () => {
        const preloader = document.getElementById('preloader');
        if(preloader) {
            setTimeout(() => {
                preloader.style.opacity = '0';
                setTimeout(() => {
                    preloader.style.display = 'none';
                }, 500);
            }, 300);
        }
    });
    
    // Ensure correct backend links are preserved for doctor/admin (original paths)
    const doctorLink = document.getElementById('doctorNavLink');
    const adminLink = document.getElementById('adminNavLink');
    if(doctorLink) doctorLink.setAttribute('href', 'backend/doc/index.php');
    if(adminLink) adminLink.setAttribute('href', 'backend/admin/index.php');
    
    // For any additional "Doctor's Login" or "Administrator Login" inside .nav-menu (double-check)
    document.querySelectorAll('.nav-menu li a').forEach(link => {
        const text = link.innerText.toLowerCase();
        if(text.includes("doctor")) {
            link.setAttribute('href', 'backend/doc/index.php');
        }
        if(text.includes("administrator")) {
            link.setAttribute('href', 'backend/admin/index.php');
        }
        if(text.includes("home")) {
            link.setAttribute('href', 'index.php');
        }
    });
    
    // Handle demo / analytics button clicks with smooth alert for prototype
    const demoBtns = document.querySelectorAll('.demo-btn, .analytics-btn, .cta-trigger');
    demoBtns.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            alert('✨ Experience the future of hospital management. Contact our team for a personalized demo and analytics tour!');
        });
    });
    
    // Dynamic copyright year
    document.getElementById('currentYear').innerText = new Date().getFullYear();
    
    // Add sticky header effect (background change on scroll)
    const header = document.querySelector('.header-area');
    window.addEventListener('scroll', () => {
        if(window.scrollY > 30) {
            header.style.background = 'rgba(255, 255, 255, 0.85)';
            header.style.backdropFilter = 'blur(18px)';
        } else {
            header.style.background = 'rgba(255, 255, 255, 0.7)';
            header.style.backdropFilter = 'blur(14px)';
        }
    });
    
    // Ensure fully responsive interactive touch for cards
    const cards = document.querySelectorAll('.feature-card, .stats-card');
    cards.forEach(card => {
        card.addEventListener('touchstart', function() {
            this.style.transform = 'scale(0.99)';
        }, { passive: true });
        card.addEventListener('touchend', function() {
            this.style.transform = '';
        });
    });
    
    // additional small improvement: add viewport resize log for debugging but keep clean
    console.log('Responsive UI ready — HMS modern interface with full mobile/tablet optimization');
</script>
</body>
</html>