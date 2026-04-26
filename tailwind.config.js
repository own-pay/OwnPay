/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './app/admin/**/*.php',
    './app/core/**/*.php',
    './app/modules/**/*.php',
    './src/**/*.php',
    './assets/js/**/*.js',
    './node_modules/flowbite/**/*.js'
  ],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        primary: {
          50: '#eef2ff',
          100: '#e0e7ff',
          200: '#c7d2fe',
          300: '#a5b4fc',
          400: '#818cf8',
          500: '#6366f1',
          600: '#4f46e5',
          700: '#4338ca',
          800: '#3730a3',
          900: '#312e81',
          950: '#1e1b4b'
        },
        navy: {
          750: '#172033',
          800: '#131c2e',
          850: '#0f172a',
          900: '#0c1322',
          950: '#080e1a'
        }
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
        mono: ['Monaco', 'Consolas', 'Liberation Mono', 'Courier New', 'monospace']
      },
      backdropBlur: {
        xs: '2px'
      },
      boxShadow: {
        'glow': '0 0 15px -3px rgba(99, 102, 241, 0.3)',
        'glow-lg': '0 0 30px -5px rgba(99, 102, 241, 0.4)',
      }
    }
  },
  plugins: [
    require('flowbite/plugin')
  ]
}
