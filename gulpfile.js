const gulp = require('gulp');
const sass = require('gulp-sass')(require('sass'));
const autoprefixer = require('gulp-autoprefixer');
const cssnano = require('gulp-cssnano');
const babel = require('gulp-babel');
const terser = require('gulp-terser');
const concat = require('gulp-concat');

// تسک CSS
gulp.task('css', () => {
    return gulp.src('assets/scss/main.scss')
        .pipe(sass())
        .pipe(autoprefixer())
        .pipe(cssnano())
        .pipe(concat('optimized.min.css'))
        .pipe(gulp.dest('assets/css'));
});

// تسک JavaScript
gulp.task('js', () => {
    return gulp.src('assets/js/modules/**/*.js')
        .pipe(babel({
            presets: ['@babel/preset-env']
        }))
        .pipe(terser())
        .pipe(gulp.dest('assets/js/dist'));
});

// تسک Watch
gulp.task('watch', () => {
    gulp.watch('assets/scss/**/*.scss', gulp.series('css'));
    gulp.watch('assets/js/modules/**/*.js', gulp.series('js'));
});

// تسک پیش‌فرض
gulp.task('default', gulp.series('css', 'js', 'watch'));