/**
 * Illustrated Qatar scenes used by the hero slider.
 * They render instantly (no network) and act as the fallback when a photo is not
 * provided in /public/images/hero/. All artwork is original and brand-neutral.
 */

const Stars = ({ count = 60, seed = 1 }: { count?: number; seed?: number }) => {
  const stars = Array.from({ length: count }, (_, i) => {
    const x = (Math.sin(i * 12.9898 + seed) * 43758.5453) % 1
    const y = (Math.sin(i * 78.233 + seed) * 12345.6789) % 1
    return { x: Math.abs(x) * 1600, y: Math.abs(y) * 380, r: (i % 3) * 0.5 + 0.6, o: 0.25 + (i % 5) * 0.12 }
  })
  return <g>{stars.map((s, i) => <circle key={i} cx={s.x} cy={s.y} r={s.r} fill="#fff" opacity={s.o} />)}</g>
}

/** Doha West Bay skyline at dusk over the Corniche, with a traditional dhow. */
export function DohaSkyline() {
  return (
    <svg viewBox="0 0 1600 900" preserveAspectRatio="xMidYMid slice" className="h-full w-full" aria-hidden>
      <defs>
        <linearGradient id="ds-sky" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#2a0612" />
          <stop offset="0.45" stopColor="#6b1631" />
          <stop offset="0.75" stopColor="#8a5a3c" />
          <stop offset="1" stopColor="#e3a857" />
        </linearGradient>
        <linearGradient id="ds-sea" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#6b1631" />
          <stop offset="1" stopColor="#2a0612" />
        </linearGradient>
        <radialGradient id="ds-sun" cx="0.5" cy="0.5" r="0.5">
          <stop offset="0" stopColor="#ffe3a3" />
          <stop offset="0.4" stopColor="#f5c56b" stopOpacity="0.8" />
          <stop offset="1" stopColor="#f5c56b" stopOpacity="0" />
        </radialGradient>
        <linearGradient id="ds-tower" x1="0" y1="0" x2="1" y2="0">
          <stop offset="0" stopColor="#3d0a1c" />
          <stop offset="0.6" stopColor="#4f0f26" />
          <stop offset="1" stopColor="#6b1631" />
        </linearGradient>
        <pattern id="ds-windows" width="14" height="18" patternUnits="userSpaceOnUse">
          <rect width="14" height="18" fill="transparent" />
          <rect x="4" y="5" width="4" height="6" fill="#f5c56b" opacity="0.55" />
        </pattern>
      </defs>
      <rect width="1600" height="900" fill="url(#ds-sky)" />
      <Stars count={70} seed={3} />
      <circle cx="1180" cy="610" r="260" fill="url(#ds-sun)" />
      <circle cx="1180" cy="610" r="70" fill="#ffd98c" opacity="0.9" />

      {/* Far skyline */}
      <g fill="#4f0f26" opacity="0.85">
        <rect x="120" y="470" width="60" height="200" />
        <rect x="190" y="430" width="44" height="240" />
        <rect x="560" y="455" width="52" height="215" />
        <rect x="880" y="440" width="48" height="230" />
        <rect x="1380" y="470" width="70" height="200" />
        <rect x="1460" y="430" width="50" height="240" />
      </g>

      {/* Hero towers */}
      <g>
        {/* Cylindrical tower with dome (Burj Doha-like) */}
        <path d="M700 670 V330 Q700 250 745 215 Q790 250 790 330 V670 Z" fill="url(#ds-tower)" />
        <path d="M700 670 V330 Q700 250 745 215 Q790 250 790 330 V670 Z" fill="url(#ds-windows)" opacity="0.9" />
        <line x1="745" y1="215" x2="745" y2="160" stroke="#a29475" strokeWidth="3" />
        {/* Hyperboloid tower (Tornado-like) */}
        <path d="M960 670 C940 560 1000 470 975 360 L1065 360 C1040 470 1100 560 1080 670 Z" fill="#4f0f26" />
        <path d="M975 360 L1065 360 C1040 470 1100 560 1080 670 L1060 670 C1080 560 1020 470 1045 360 Z" fill="#a29475" opacity="0.35" />
        {/* Slanted glass towers */}
        <path d="M430 670 V380 L500 340 V670 Z" fill="url(#ds-tower)" />
        <path d="M430 670 V380 L500 340 V670 Z" fill="url(#ds-windows)" opacity="0.7" />
        <path d="M515 670 V300 L575 270 L575 670 Z" fill="#3d0a1c" />
        <path d="M515 670 V300 L575 270 L575 670 Z" fill="url(#ds-windows)" opacity="0.6" />
        <path d="M1150 670 V420 Q1195 380 1240 420 V670 Z" fill="url(#ds-tower)" />
        <path d="M1150 670 V420 Q1195 380 1240 420 V670 Z" fill="url(#ds-windows)" opacity="0.8" />
        <path d="M1260 670 V360 L1320 330 V670 Z" fill="#3d0a1c" />
        <rect x="250" y="400" width="70" height="270" fill="url(#ds-tower)" />
        <rect x="250" y="400" width="70" height="270" fill="url(#ds-windows)" opacity="0.7" />
        <path d="M330 670 V455 L360 430 L390 455 V670 Z" fill="#4f0f26" />
        <rect x="620" y="420" width="62" height="250" fill="#4f0f26" />
        <rect x="620" y="420" width="62" height="250" fill="url(#ds-windows)" opacity="0.5" />
        <rect x="810" y="385" width="58" height="285" fill="url(#ds-tower)" />
        <rect x="810" y="385" width="58" height="285" fill="url(#ds-windows)" opacity="0.8" />
      </g>

      {/* Corniche + sea */}
      <rect x="0" y="668" width="1600" height="8" fill="#a29475" opacity="0.8" />
      <rect x="0" y="676" width="1600" height="224" fill="url(#ds-sea)" />
      <g opacity="0.35">
        {Array.from({ length: 14 }, (_, i) => (
          <rect key={i} x={1060 + ((i * 37) % 240)} y={700 + i * 12} width={120 - i * 6} height="3" rx="1.5" fill="#f5c56b" />
        ))}
      </g>

      {/* Dhow */}
      <g transform="translate(260 700)">
        <path d="M0 40 Q90 70 190 40 L175 60 Q95 80 15 60 Z" fill="#2a0612" />
        <path d="M95 40 L95 -90" stroke="#2a0612" strokeWidth="4" />
        <path d="M97 -85 Q170 -30 175 30 L97 30 Z" fill="#f6edd6" opacity="0.92" />
        <path d="M60 -40 L150 -95" stroke="#2a0612" strokeWidth="3" />
      </g>
    </svg>
  )
}

/** A contemporary education ministry campus with arcades and geometric screens. */
export function MinistryOfEducation() {
  return (
    <svg viewBox="0 0 1600 900" preserveAspectRatio="xMidYMid slice" className="h-full w-full" aria-hidden>
      <defs>
        <linearGradient id="me-sky" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#3d0a1c" />
          <stop offset="0.6" stopColor="#264775" />
          <stop offset="1" stopColor="#c9b28a" />
        </linearGradient>
        <linearGradient id="me-wall" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#f6edd6" />
          <stop offset="1" stopColor="#d9c7a0" />
        </linearGradient>
        <linearGradient id="me-glass" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#3a5d8f" />
          <stop offset="1" stopColor="#4f0f26" />
        </linearGradient>
        <pattern id="me-mashrabiya" width="40" height="40" patternUnits="userSpaceOnUse">
          <path d="M20 0 L40 20 L20 40 L0 20 Z" fill="none" stroke="#a29475" strokeWidth="1.6" opacity="0.8" />
          <circle cx="20" cy="20" r="5" fill="none" stroke="#a29475" strokeWidth="1.2" opacity="0.7" />
        </pattern>
        <radialGradient id="me-glow" cx="0.5" cy="0.35" r="0.6">
          <stop offset="0" stopColor="#f5c56b" stopOpacity="0.45" />
          <stop offset="1" stopColor="#f5c56b" stopOpacity="0" />
        </radialGradient>
      </defs>
      <rect width="1600" height="900" fill="url(#me-sky)" />
      <rect width="1600" height="900" fill="url(#me-glow)" />
      <Stars count={40} seed={7} />

      {/* Flag masts */}
      {[380, 1220].map((x) => (
        <g key={x}>
          <rect x={x} y="250" width="5" height="420" fill="#e3e9f2" />
          <path d={`M${x + 5} 255 h120 v70 h-120 z`} fill="#8a1538" />
          <path d={`M${x + 5} 255 h34 l-10 7.8 l10 7.8 l-10 7.8 l10 7.8 l-10 7.8 l10 7.8 l-10 7.8 l10 7.8 l-10 7.8 h-24 z`} fill="#fff" />
        </g>
      ))}

      {/* Main building */}
      <g>
        <rect x="470" y="330" width="660" height="340" fill="url(#me-wall)" />
        <rect x="470" y="310" width="660" height="26" fill="#a29475" />
        <rect x="500" y="290" width="600" height="22" fill="#e5cd8a" />
        {/* central glass atrium with arch */}
        <path d="M700 670 V470 Q800 380 900 470 V670 Z" fill="url(#me-glass)" />
        <path d="M700 670 V470 Q800 380 900 470 V670 Z" fill="url(#me-mashrabiya)" opacity="0.55" />
        <path d="M720 670 V480 Q800 405 880 480 V670" fill="none" stroke="#e5cd8a" strokeWidth="3" />
        {/* arcades */}
        {Array.from({ length: 4 }, (_, i) => (
          <path key={`l${i}`} d={`M${500 + i * 48} 670 V560 Q${520 + i * 48} 520 ${540 + i * 48} 560 V670 Z`} fill="#4f0f26" opacity="0.85" />
        ))}
        {Array.from({ length: 4 }, (_, i) => (
          <path key={`r${i}`} d={`M${920 + i * 48} 670 V560 Q${940 + i * 48} 520 ${960 + i * 48} 560 V670 Z`} fill="#4f0f26" opacity="0.85" />
        ))}
        {/* upper mashrabiya screens */}
        <rect x="500" y="370" width="180" height="150" fill="url(#me-mashrabiya)" />
        <rect x="920" y="370" width="180" height="150" fill="url(#me-mashrabiya)" />
        {/* wings */}
        <rect x="250" y="430" width="220" height="240" fill="#e5d7b5" />
        <rect x="1130" y="430" width="220" height="240" fill="#e5d7b5" />
        {Array.from({ length: 5 }, (_, i) => <rect key={`wl${i}`} x={270 + i * 40} y="470" width="22" height="150" fill="url(#me-glass)" opacity="0.8" />)}
        {Array.from({ length: 5 }, (_, i) => <rect key={`wr${i}`} x={1150 + i * 40} y="470" width="22" height="150" fill="url(#me-glass)" opacity="0.8" />)}
      </g>

      {/* Open book emblem above entrance (education motif) */}
      <g transform="translate(800 240)">
        <circle r="52" fill="#3d0a1c" stroke="#a29475" strokeWidth="3" />
        <path d="M-30 -8 Q-15 -18 0 -8 Q15 -18 30 -8 V22 Q15 12 0 22 Q-15 12 -30 22 Z" fill="#f6edd6" />
        <line x1="0" y1="-8" x2="0" y2="22" stroke="#a29475" strokeWidth="2" />
        <path d="M-12 -30 L0 -42 L12 -30" fill="none" stroke="#a29475" strokeWidth="3" />
      </g>

      {/* Plaza */}
      <rect x="0" y="668" width="1600" height="232" fill="#3d0a1c" />
      <path d="M0 668 H1600" stroke="#a29475" strokeWidth="4" />
      <path d="M700 668 L520 900 H1080 L900 668 Z" fill="#4f0f26" />
      {Array.from({ length: 8 }, (_, i) => (
        <g key={i} transform={`translate(${130 + i * 190} 640)`}>
          <rect x="-3" y="0" width="6" height="30" fill="#2a0612" />
          <circle cy="-6" r="20" fill="#0f3d2e" />
        </g>
      ))}
    </svg>
  )
}

/** Stepped geometric museum on the water with a crescent moon — excellence & innovation. */
export function IslamicArtMuseum() {
  return (
    <svg viewBox="0 0 1600 900" preserveAspectRatio="xMidYMid slice" className="h-full w-full" aria-hidden>
      <defs>
        <linearGradient id="mi-sky" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#2a0612" />
          <stop offset="0.7" stopColor="#4f0f26" />
          <stop offset="1" stopColor="#264775" />
        </linearGradient>
        <linearGradient id="mi-stone" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#f6edd6" />
          <stop offset="1" stopColor="#c9b28a" />
        </linearGradient>
        <linearGradient id="mi-shade" x1="0" y1="0" x2="1" y2="0">
          <stop offset="0" stopColor="#b59d72" />
          <stop offset="1" stopColor="#8f7a55" />
        </linearGradient>
        <linearGradient id="mi-water" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#6b1631" />
          <stop offset="1" stopColor="#2a0612" />
        </linearGradient>
      </defs>
      <rect width="1600" height="900" fill="url(#mi-sky)" />
      <Stars count={110} seed={11} />
      <g transform="translate(1260 170)">
        <circle r="46" fill="#f6edd6" />
        <circle cx="18" cy="-10" r="42" fill="#3d0a1c" />
      </g>

      {/* Museum mass */}
      <g transform="translate(560 250)">
        <polygon points="0,420 480,420 480,200 0,200" fill="url(#mi-stone)" />
        <polygon points="480,420 560,380 560,210 480,200" fill="url(#mi-shade)" />
        <polygon points="60,200 420,200 420,110 60,110" fill="url(#mi-stone)" />
        <polygon points="420,200 480,200 480,120 420,110" fill="url(#mi-shade)" />
        <polygon points="120,110 360,110 360,40 120,40" fill="url(#mi-stone)" />
        <polygon points="360,110 420,110 410,45 360,40" fill="url(#mi-shade)" />
        <polygon points="170,40 310,40 280,-20 200,-20" fill="#e5cd8a" />
        <circle cx="240" cy="-26" r="10" fill="#a29475" />
        {/* signature oculus */}
        <path d="M200 110 Q240 60 280 110 Z" fill="#3d0a1c" />
        <path d="M180 330 Q240 250 300 330 V420 H180 Z" fill="#3d0a1c" />
        <path d="M195 330 Q240 270 285 330" fill="none" stroke="#a29475" strokeWidth="3" />
        {Array.from({ length: 5 }, (_, i) => <rect key={i} x={30 + i * 30} y="260" width="12" height="60" fill="#3d0a1c" opacity="0.4" />)}
        {Array.from({ length: 5 }, (_, i) => <rect key={`r${i}`} x={330 + i * 30} y="260" width="12" height="60" fill="#3d0a1c" opacity="0.4" />)}
      </g>

      {/* Palms on the promenade */}
      {[180, 320, 1360, 1480].map((x, i) => (
        <g key={i} transform={`translate(${x} 670)`}>
          <path d="M0 0 Q6 -90 2 -170" stroke="#2a0612" strokeWidth="9" fill="none" />
          {[-60, -25, 15, 55, 95].map((a, j) => (
            <path key={j} d={`M2 -170 q${Math.cos((a * Math.PI) / 180) * 70} ${Math.sin((a * Math.PI) / 180) * 30 - 10} ${Math.cos((a * Math.PI) / 180) * 110} ${Math.sin((a * Math.PI) / 180) * 70 + 20}`} stroke="#2a0612" strokeWidth="7" fill="none" strokeLinecap="round" />
          ))}
        </g>
      ))}

      <rect x="0" y="668" width="1600" height="232" fill="url(#mi-water)" />
      <rect x="0" y="668" width="1600" height="5" fill="#a29475" opacity="0.7" />
      {/* reflection */}
      <g opacity="0.18" transform="translate(560 1090) scale(1 -1)">
        <polygon points="0,420 480,420 480,200 0,200" fill="#f6edd6" />
        <polygon points="60,200 420,200 420,110 60,110" fill="#f6edd6" />
      </g>
    </svg>
  )
}

/** Desert dunes, wind tower heritage house and palms — culture & identity. */
export function HeritageDesert() {
  return (
    <svg viewBox="0 0 1600 900" preserveAspectRatio="xMidYMid slice" className="h-full w-full" aria-hidden>
      <defs>
        <linearGradient id="hd-sky" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#4f0f26" />
          <stop offset="0.55" stopColor="#8a5a3c" />
          <stop offset="1" stopColor="#f0c27b" />
        </linearGradient>
        <linearGradient id="hd-dune1" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#d9a35b" />
          <stop offset="1" stopColor="#a8752f" />
        </linearGradient>
        <linearGradient id="hd-dune2" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#b88444" />
          <stop offset="1" stopColor="#6d4a1f" />
        </linearGradient>
        <radialGradient id="hd-sun" cx="0.5" cy="0.5" r="0.5">
          <stop offset="0" stopColor="#fff1c9" />
          <stop offset="1" stopColor="#f5c56b" stopOpacity="0" />
        </radialGradient>
      </defs>
      <rect width="1600" height="900" fill="url(#hd-sky)" />
      <circle cx="420" cy="470" r="240" fill="url(#hd-sun)" />
      <circle cx="420" cy="470" r="80" fill="#ffe3a3" />
      <path d="M0 560 Q300 470 620 540 T1200 520 T1600 540 V900 H0 Z" fill="url(#hd-dune1)" opacity="0.9" />

      {/* Heritage house with barjeel (wind tower) */}
      <g transform="translate(980 390)">
        <rect x="0" y="120" width="360" height="170" fill="#e8d3a7" />
        <rect x="0" y="110" width="360" height="14" fill="#c9a86b" />
        {Array.from({ length: 9 }, (_, i) => <rect key={i} x={8 + i * 40} y="98" width="22" height="14" fill="#c9a86b" />)}
        <rect x="250" y="-20" width="80" height="140" fill="#e8d3a7" />
        <rect x="250" y="-30" width="80" height="12" fill="#c9a86b" />
        {Array.from({ length: 3 }, (_, i) => <rect key={i} x={262 + i * 22} y="0" width="10" height="70" fill="#6d4a1f" />)}
        <path d="M150 290 V210 Q180 170 210 210 V290 Z" fill="#6d4a1f" />
        {Array.from({ length: 3 }, (_, i) => <path key={i} d={`M${30 + i * 40} 230 V190 Q${40 + i * 40} 175 ${50 + i * 40} 190 V230 Z`} fill="#6d4a1f" opacity="0.85" />)}
      </g>

      {/* Palms */}
      {[900, 1390, 1480].map((x, i) => (
        <g key={i} transform={`translate(${x} ${680 - i * 8})`}>
          <path d="M0 0 Q-6 -110 4 -210" stroke="#3b2a12" strokeWidth="10" fill="none" />
          {[-150, -115, -70, -30, 10].map((a, j) => (
            <path key={j} d={`M4 -210 q${Math.cos((a * Math.PI) / 180) * 70} ${Math.sin((a * Math.PI) / 180) * 40} ${Math.cos((a * Math.PI) / 180) * 120} ${Math.sin((a * Math.PI) / 180) * 30 + 60}`} stroke="#2f4a22" strokeWidth="8" fill="none" strokeLinecap="round" />
          ))}
        </g>
      ))}

      <path d="M0 690 Q400 610 820 680 T1600 650 V900 H0 Z" fill="url(#hd-dune2)" />
      {/* Camel caravan silhouette */}
      <g fill="#3b2a12" transform="translate(180 610)">
        {[0, 120, 240].map((dx) => (
          <path key={dx} transform={`translate(${dx} ${dx / 12})`} d="M0 40 q10 -30 30 -30 q10 -18 22 0 q14 -14 26 4 q8 -16 20 -10 l6 10 l-8 4 q-6 10 -14 12 v34 h-6 v-30 h-40 v30 h-6 v-34 q-18 -4 -30 10 z" />
        ))}
      </g>
    </svg>
  )
}
