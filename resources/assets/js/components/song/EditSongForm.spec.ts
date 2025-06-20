import { screen } from '@testing-library/vue'
import { ref } from 'vue'
import { expect, it } from 'vitest'
import UnitTestCase from '@/__tests__/UnitTestCase'
import factory from '@/__tests__/factory'
import { arrayify } from '@/utils/helpers'
import { eventBus } from '@/utils/eventBus'
import { ModalContextKey } from '@/symbols'
import type { SongUpdateResult } from '@/stores/songStore'
import { songStore } from '@/stores/songStore'
import { MessageToasterStub } from '@/__tests__/stubs'
import EditSongForm from './EditSongForm.vue'

let songs: Song[]

new class extends UnitTestCase {
  protected test () {
    it('edits a single song', async () => {
      const result: SongUpdateResult = {
        songs: [],
        albums: [],
        artists: [],
        removed: {
          albums: [],
          artists: [],
        },
      }

      const updateMock = this.mock(songStore, 'update').mockResolvedValue(result)
      const emitMock = this.mock(eventBus, 'emit')
      const alertMock = this.mock(MessageToasterStub.value, 'success')

      const { html } = await this.renderComponent(factory('song', {
        title: 'Rocket to Heaven',
        artist_name: 'Led Zeppelin',
        album_name: 'IV',
        album_cover: 'http://test/album.jpg',
        genre: 'Rock',
      }))

      expect(html()).toMatchSnapshot()

      await this.type(screen.getByTestId('title-input'), 'Highway to Hell')
      await this.type(screen.getByTestId('artist-input'), 'AC/DC')
      await this.type(screen.getByTestId('albumArtist-input'), 'AC/DC')
      await this.type(screen.getByTestId('album-input'), 'Back in Black')
      await this.type(screen.getByTestId('disc-input'), '1')
      await this.type(screen.getByTestId('track-input'), '10')
      await this.type(screen.getByTestId('genre-input'), 'Rock & Roll')
      await this.type(screen.getByTestId('year-input'), '1971')
      await this.type(screen.getByTestId('lyrics-input'), 'I\'m gonna make him an offer he can\'t refuse')

      await this.user.click(screen.getByRole('button', { name: 'Update' }))

      expect(updateMock).toHaveBeenCalledWith(songs, {
        title: 'Highway to Hell',
        album_name: 'Back in Black',
        artist_name: 'AC/DC',
        album_artist_name: 'AC/DC',
        lyrics: 'I\'m gonna make him an offer he can\'t refuse',
        track: 10,
        disc: 1,
        genre: 'Rock & Roll',
        year: 1971,
      })

      expect(alertMock).toHaveBeenCalledWith('Updated 1 song.')
      expect(emitMock).toHaveBeenCalledWith('SONGS_UPDATED', result)
    })

    it('edits multiple songs', async () => {
      const result: SongUpdateResult = {
        songs: [],
        albums: [],
        artists: [],
        removed: {
          albums: [],
          artists: [],
        },
      }

      const updateMock = this.mock(songStore, 'update').mockResolvedValue(result)
      const emitMock = this.mock(eventBus, 'emit')
      const alertMock = this.mock(MessageToasterStub.value, 'success')

      const { html } = await this.renderComponent(factory('song', 3))

      expect(html()).toMatchSnapshot()
      expect(screen.queryByTestId('title-input')).toBeNull()
      expect(screen.queryByTestId('lyrics-input')).toBeNull()

      await this.type(screen.getByTestId('artist-input'), 'AC/DC')
      await this.type(screen.getByTestId('albumArtist-input'), 'AC/DC')
      await this.type(screen.getByTestId('album-input'), 'Back in Black')
      await this.type(screen.getByTestId('disc-input'), '1')
      await this.type(screen.getByTestId('track-input'), '10')
      await this.type(screen.getByTestId('year-input'), '1990')
      await this.type(screen.getByTestId('genre-input'), 'Pop')

      await this.user.click(screen.getByRole('button', { name: 'Update' }))

      expect(updateMock).toHaveBeenCalledWith(songs, {
        album_name: 'Back in Black',
        artist_name: 'AC/DC',
        album_artist_name: 'AC/DC',
        track: 10,
        disc: 1,
        genre: 'Pop',
        year: 1990,
      })

      expect(alertMock).toHaveBeenCalledWith('Updated 3 songs.')
      expect(emitMock).toHaveBeenCalledWith('SONGS_UPDATED', result)
    })

    it('displays artist name if all songs have the same artist', async () => {
      await this.renderComponent(factory('song', 4, {
        artist_id: 'led-zeppelin',
        artist_name: 'Led Zeppelin',
        album_id: 'iv',
        album_name: 'IV',
      }))

      expect(screen.getByTestId('displayed-artist-name').textContent).toBe('Led Zeppelin')
      expect(screen.getByTestId('displayed-album-name').textContent).toBe('IV')
    })
  }

  private async renderComponent (_songs: MaybeArray<Song>, initialTab: EditSongFormTabName = 'details') {
    songs = arrayify(_songs)

    const rendered = this.render(EditSongForm, {
      global: {
        provide: {
          [<symbol>ModalContextKey]: [ref({
            songs,
            initialTab,
          })],
        },
      },
    })

    await this.tick()

    return rendered
  }
}
