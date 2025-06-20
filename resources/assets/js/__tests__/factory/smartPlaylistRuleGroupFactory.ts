import type { Faker } from '@faker-js/faker'
import factory from 'factoria'

export default (faker: Faker): SmartPlaylistRuleGroup => ({
  id: faker.string.uuid(),
  rules: factory('smart-playlist-rule', 3),
})
