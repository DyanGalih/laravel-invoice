import { defineStore } from 'pinia'
import createUuid from '@/scripts/helpers/uuid'

export const useNotificationStore = (useWindow = false) => {
  const defineStoreFunc = useWindow ? window.pinia.defineStore : defineStore

  return defineStoreFunc('notification', {

    state: () => ({
      active: false,
      autoHide: true,
      notifications: [],
    }),

    actions: {
      showNotification(notification) {
        this.notifications.push({
          ...notification,
          id: createUuid(),
        })
      },

      hideNotification(data) {
        this.notifications = this.notifications.filter((notification) => {
          return notification.id != data.id
        })
      },
    },
  })()
}
