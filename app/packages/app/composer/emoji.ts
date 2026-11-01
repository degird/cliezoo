/**
 * The emoji the composer offers.
 *
 * A curated set rather than all of Unicode: the few hundred people actually
 * use at work, each with the words somebody would type to find it. The full
 * table is thousands of entries and tens of kilobytes to load for a picker
 * that is opened to find a thumbs-up; anybody who wants the rest has the
 * emoji keyboard their system already gives them, and typing one works.
 */
import { __ } from '@wordpress/i18n';

export interface EmojiGroup {
	id: string;
	label: string;
	emoji: Array< { char: string; words: string } >;
}

/** The reactions offered at a glance, before the full picker. */
export const QUICK = [ '👍', '❤️', '😂', '🎉', '😮', '😢', '🙏', '✅' ];

/**
 * One line per emoji: the character, then the words that find it.
 *
 * @param lines Lines.
 */
function parse( lines: string ): Array< { char: string; words: string } > {
	return lines
		.trim()
		.split( '\n' )
		.map( ( line ) => {
			const [ char = '', ...words ] = line.trim().split( ' ' );
			return { char, words: words.join( ' ' ) };
		} );
}

/**
 * The groups, labelled in the reader's language.
 */
export function emojiGroups(): EmojiGroup[] {
	return [
		{
			id: 'smileys',
			label: __( 'Smileys', 'cliezoo' ),
			emoji: parse( `
😀 grin happy smile
😃 smile happy joy
😄 laugh happy smile
😁 beam grin
😆 laughing squint
😅 sweat relief nervous
😂 joy tears laugh lol
🤣 rofl rolling laugh
🙂 slight smile
🙃 upside down silly
😉 wink
😊 blush happy
😇 halo angel innocent
🥰 love hearts adore
😍 heart eyes love
🤩 star struck wow
😘 kiss
😋 yum tasty
😛 tongue
😜 wink tongue crazy
🤪 zany crazy
🤔 thinking hmm
🤨 raised eyebrow sceptical
😐 neutral meh
😑 expressionless
😶 no mouth speechless
🙄 eye roll
😏 smirk
😬 grimace awkward
😌 relieved calm
😔 pensive sad
😪 sleepy
😴 sleeping tired
😷 mask sick
🤒 thermometer ill
🤯 mind blown exploding
🥳 party celebrate birthday
😎 cool sunglasses
🤓 nerd geek
🧐 monocle inspect
😕 confused
😟 worried
😮 open mouth wow surprised
😲 astonished shocked
😳 flushed embarrassed
🥺 pleading puppy
😢 cry sad tear
😭 sob crying
😱 scream fear
😤 steam huff frustrated
😡 angry rage
🤬 swearing cursing
😈 devil
💀 skull dead
🤡 clown
👻 ghost
🤖 robot bot
` ),
		},
		{
			id: 'gestures',
			label: __( 'People and gestures', 'cliezoo' ),
			emoji: parse( `
👍 thumbs up yes like approve
👎 thumbs down no dislike
👌 ok okay perfect
✌️ victory peace
🤞 fingers crossed luck hope
🤝 handshake deal agree
👏 clap applause bravo
🙌 raised hands hooray
👐 open hands
🙏 pray please thanks thank you
✋ raised hand stop high five
👋 wave hello bye hi
🤙 call me shaka
💪 muscle strong flex
👀 eyes look watching
🧠 brain think smart
🫡 salute
🤷 shrug dunno whatever
🤦 facepalm
🙋 raising hand question
💁 tipping hand info
🙅 no gesture
🙆 ok gesture
🧑‍💻 developer coder laptop
🧑‍🎨 artist designer
🧑‍💼 office worker business
` ),
		},
		{
			id: 'hearts',
			label: __( 'Hearts and symbols', 'cliezoo' ),
			emoji: parse( `
❤️ heart love red
🧡 orange heart
💛 yellow heart
💚 green heart
💙 blue heart
💜 purple heart
🖤 black heart
🤍 white heart
💔 broken heart
💯 hundred perfect score
✅ check done yes tick complete
☑️ ballot check
✔️ tick check
❌ cross no wrong cancel
❗ exclamation important
❓ question
⚠️ warning caution
🚫 prohibited forbidden no
⭐ star favourite
🌟 glowing star
✨ sparkles new shiny magic
🔥 fire hot lit
⚡ lightning fast zap
💥 boom collision
💡 idea bulb light
🎯 target bullseye goal
🏆 trophy win award
🥇 gold medal first
🎉 party tada celebrate congrats
🎊 confetti celebrate
🎁 gift present
🔔 bell notification
🔒 lock locked secure private
🔓 unlocked open
🔑 key
` ),
		},
		{
			id: 'work',
			label: __( 'Work', 'cliezoo' ),
			emoji: parse( `
📌 pin pinned
📎 paperclip attachment
📝 memo note write
✏️ pencil edit
📄 page document file
📁 folder
📂 open folder
📊 chart bar stats
📈 chart up growth increase
📉 chart down decrease
🗂️ dividers index
📅 calendar date
🗓️ spiral calendar schedule
⏰ alarm clock time
⏳ hourglass waiting
⌛ hourglass done time up
🕒 clock time
📞 phone call
📱 mobile phone
💻 laptop computer
🖥️ desktop computer
⌨️ keyboard
🖱️ mouse
🖨️ printer
📧 email mail
📨 incoming envelope
📬 mailbox
💬 speech bubble chat message comment
🗨️ speech
📣 megaphone announcement
🔍 search magnifying glass
🔗 link chain
🧾 receipt invoice
💰 money bag
💵 dollar cash
💳 card payment credit
🏦 bank
🚀 rocket launch ship
🛠️ tools build fix
🔧 wrench fix
⚙️ gear settings
🧪 test experiment
🐛 bug
📦 package box delivery
🏁 finish flag
🚧 construction work in progress wip
🗑️ trash bin delete
` ),
		},
		{
			id: 'nature',
			label: __( 'Nature, food and travel', 'cliezoo' ),
			emoji: parse( `
☀️ sun sunny
🌤️ sun cloud
🌧️ rain
❄️ snow cold
🌈 rainbow
🌱 seedling grow sprout
🌸 blossom flower spring
🌻 sunflower
🍀 clover luck
🐶 dog puppy
🐱 cat kitten
🦄 unicorn
🐝 bee busy
☕ coffee
🍵 tea
🍺 beer
🍷 wine
🥂 cheers toast
🍕 pizza
🍔 burger
🍰 cake
🎂 birthday cake
🍩 doughnut
🍎 apple
✈️ plane travel flight
🚗 car
🏠 house home
🏢 office building
🌍 globe world earth
🏖️ beach holiday vacation
` ),
		},
	];
}

/**
 * Emoji whose words start with what was typed.
 *
 * @param groups The groups.
 * @param query  What was typed.
 */
export function findEmoji(
	groups: EmojiGroup[],
	query: string
): Array< { char: string; words: string } > {
	const wanted = query.trim().toLowerCase();
	if ( ! wanted ) {
		return [];
	}
	const out: Array< { char: string; words: string } > = [];
	for ( const group of groups ) {
		for ( const one of group.emoji ) {
			if (
				one.words
					.split( ' ' )
					.some( ( word ) => word.startsWith( wanted ) )
			) {
				out.push( one );
			}
		}
	}
	return out;
}
