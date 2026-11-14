import '../sass/crater.scss'
import 'floating-vue/dist/style.css'
import '@/scripts/plugins/axios.js'
import * as VueRouter from 'vue-router'
import router from '@/scripts/router/index'
import * as pinia from 'pinia'
import * as Vue from 'vue'
import * as Vuelidate from '@vuelidate/core'

window.pinia = pinia
window.Vuelidate = Vuelidate

import Crater from './Crater'

window.Vue = Vue
window.router = router
window.VueRouter = VueRouter

window.Crater = new Crater()
